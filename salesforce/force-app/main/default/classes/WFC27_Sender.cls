public without sharing class WFC27_Sender implements Queueable, Database.AllowsCallouts {
    private Id packetId;
    public WFC27_Sender(Id packetId) { this.packetId = packetId; }

    public void execute(QueueableContext context) {
        WFC27_Packet__c packet;
        Boolean receiptConfirmed = false;
        Map<String,Object> body;
        if (packetId == null) {
            body = new Map<String,Object>{'summary' => new Map<String,Object>(), 'posts' => new List<Object>(), 'postmeta' => new List<Object>()};
        } else {
            packet = [SELECT Id, Payload__c, Status__c FROM WFC27_Packet__c WHERE Id = :packetId LIMIT 1];
            body = (Map<String,Object>) JSON.deserializeUntyped(packet.Payload__c);
        }
        Map<String,Object> summary = (Map<String,Object>) body.get('summary');
        List<String> ackIds = new List<String>();
        for (WFC27_Packet__c completed : [SELECT Id FROM WFC27_Packet__c WHERE Status__c = 'completed' ORDER BY Completed_At__c DESC LIMIT 100]) ackIds.add(completed.Id);
        List<Map<String,Object>> deletionAcks = new List<Map<String,Object>>();
        List<WFC27_Deletion_Ack__c> ackRows = [SELECT Id, Source_ID__c, WP_Post_ID__c FROM WFC27_Deletion_Ack__c WHERE Status__c = 'pending' LIMIT 100];
        for (WFC27_Deletion_Ack__c row : ackRows) deletionAcks.add(new Map<String,Object>{'sf_id' => row.Source_ID__c, 'wp_id' => row.WP_Post_ID__c});
        summary.put('acknowledged_results', ackIds);
        summary.put('acknowledged_deletions', deletionAcks);
        List<WFC27_Settings__c> trainSettings = [SELECT Train_Paused__c FROM WFC27_Settings__c WHERE Name = 'Default' LIMIT 1];
        summary.put('train_state', !trainSettings.isEmpty() && trainSettings[0].Train_Paused__c ? 'paused' : 'running');
        HttpRequest request = new HttpRequest();
        request.setEndpoint('callout:WFC27_WordPress/?rest_route=/wfc27/v1/train');
        request.setMethod('POST');
        request.setHeader('Content-Type', 'application/json');
        request.setTimeout(30000);
        request.setBody(JSON.serialize(body));
        try {
            HttpResponse response = new Http().send(request);
            if (response.getStatusCode() < 200 || response.getStatusCode() > 299) throw new WFC27_TransportException('WordPress returned HTTP ' + response.getStatusCode());
            Map<String,Object> result = (Map<String,Object>) JSON.deserializeUntyped(response.getBody());
            Map<String,Object> responseSummary = (Map<String,Object>) result.get('summary');
            if (responseSummary == null) throw new WFC27_TransportException('WordPress omitted the response summary');
            String received = (String) responseSummary.get('received_packet_id');
            if (packet != null && received != String.valueOf(packet.Id)) throw new WFC27_TransportException('WordPress did not acknowledge this packet');
            if (packet != null) {
                packet.Status__c = 'received'; packet.Sent_At__c = System.now(); update packet;
                receiptConfirmed = true;
                Set<String> sentIds = new Set<String>();
                for (WFC27_Queue__c item : [SELECT Source_ID__c FROM WFC27_Queue__c WHERE Packet__c = :packet.Id]) sentIds.add(item.Source_ID__c);
                List<WFC27_Map__c> sentMaps = [SELECT Id, Last_Sent_At__c FROM WFC27_Map__c WHERE Source_ID__c IN :sentIds];
                for (WFC27_Map__c mapRow : sentMaps) mapRow.Last_Sent_At__c = packet.Sent_At__c;
                if (!sentMaps.isEmpty()) update sentMaps;
            }
            if (!ackIds.isEmpty()) {
                List<WFC27_Packet__c> acknowledged = [SELECT Id, Status__c FROM WFC27_Packet__c WHERE Id IN :ackIds AND Status__c = 'completed'];
                for (WFC27_Packet__c row : acknowledged) row.Status__c = 'acknowledged';
                if (!acknowledged.isEmpty()) update acknowledged;
            }
            if (!ackRows.isEmpty()) { for (WFC27_Deletion_Ack__c row : ackRows) row.Status__c = 'sent'; update ackRows; }
            processResults(result);
            WFC27_Settings__c healthy = WFC27_Engine.settings();
            if (healthy.Last_Error__c != null) { healthy.Last_Error__c = null; update healthy; }
        } catch (Exception error) {
            if (packet != null) { packet.Status__c = receiptConfirmed ? 'received' : 'error'; packet.Error__c = error.getMessage().left(32768); update packet; }
            else {
                WFC27_Settings__c settings = WFC27_Engine.settings();
                settings.Last_Error__c = error.getMessage().left(32768);
                update settings;
            }
        }
    }

    private static void processResults(Map<String,Object> result) {
        List<Object> completed = (List<Object>) result.get('completed');
        List<Object> deleted = (List<Object>) result.get('deleted');
        List<Object> errors = (List<Object>) result.get('errors');
        if (completed == null || deleted == null || errors == null) throw new WFC27_TransportException('WordPress response is incomplete');
        Set<String> sourceIds = new Set<String>();
        Set<String> packetIds = new Set<String>();
        for (Object value : completed) {
            Map<String,Object> item = (Map<String,Object>) value;
            packetIds.add((String) item.get('packet_id'));
            for (Object postValue : (List<Object>) item.get('posts')) sourceIds.add((String) ((Map<String,Object>) postValue).get('sf_id'));
        }
        for (Object value : deleted) sourceIds.add((String) ((Map<String,Object>) value).get('sf_id'));
        Map<String,WFC27_Map__c> maps = new Map<String,WFC27_Map__c>();
        for (WFC27_Map__c mapRow : [SELECT Id, Source_ID__c, WP_Post_ID__c, WP_Status__c, Status_Entered_At__c, Meta_IDs_JSON__c FROM WFC27_Map__c WHERE Source_ID__c IN :sourceIds]) maps.put(mapRow.Source_ID__c, mapRow);
        Map<Id,WFC27_Map__c> mapWrites = new Map<Id,WFC27_Map__c>();
        List<WFC27_Deletion_Ack__c> ackWrites = new List<WFC27_Deletion_Ack__c>();
        Set<String> deletedSourceIds = new Set<String>();
        for (Object value : completed) {
            Map<String,Object> item = (Map<String,Object>) value;
            for (Object postValue : (List<Object>) item.get('posts')) {
                Map<String,Object> post = (Map<String,Object>) postValue;
                WFC27_Map__c mapRow = maps.get((String) post.get('sf_id'));
                if (mapRow == null) continue;
                String newStatus = (String) post.get('post_status');
                if (mapRow.WP_Status__c != newStatus || mapRow.Status_Entered_At__c == null) mapRow.Status_Entered_At__c = System.now();
                mapRow.WP_Post_ID__c = decimalValue(post.get('wp_id'));
                mapRow.WP_Status__c = newStatus;
                mapWrites.put(mapRow.Id, mapRow);
            }
            List<Object> metaResults = (List<Object>) item.get('postmeta');
            if (metaResults != null) for (Object metaValue : metaResults) {
                Map<String,Object> meta = (Map<String,Object>) metaValue;
                WFC27_Map__c mapRow = maps.get((String) meta.get('sf_id'));
                if (mapRow == null) continue;
                Map<String,Object> metaIds = String.isBlank(mapRow.Meta_IDs_JSON__c) ? new Map<String,Object>() :
                    (Map<String,Object>) JSON.deserializeUntyped(mapRow.Meta_IDs_JSON__c);
                Decimal metaId = decimalValue(meta.get('wp_meta_id'));
                if (metaId == null || metaId == 0) metaIds.remove((String) meta.get('meta_key'));
                else metaIds.put((String) meta.get('meta_key'), metaId);
                mapRow.Meta_IDs_JSON__c = JSON.serialize(metaIds);
                mapWrites.put(mapRow.Id, mapRow);
            }
        }
        for (Object value : deleted) {
            Map<String,Object> item = (Map<String,Object>) value;
            String sourceId = (String) item.get('sf_id');
            deletedSourceIds.add(sourceId);
            Decimal wpId = decimalValue(item.get('wp_id'));
            WFC27_Map__c mapRow = maps.get(sourceId);
            if (mapRow != null && mapRow.WP_Post_ID__c == wpId) {
                mapRow.WP_Post_ID__c = null;
                mapRow.WP_Status__c = null;
                mapWrites.put(mapRow.Id, mapRow);
            }
            ackWrites.add(new WFC27_Deletion_Ack__c(Source_ID__c=sourceId, WP_Post_ID__c=wpId, Status__c='pending'));
        }
        if (!mapWrites.isEmpty()) update mapWrites.values();
        if (!ackWrites.isEmpty()) insert ackWrites;
        if (!deletedSourceIds.isEmpty()) {
            List<WFC27_Queue__c> waiting = [SELECT Id, Status__c FROM WFC27_Queue__c WHERE Source_ID__c IN :deletedSourceIds AND Status__c = 'pending'];
            for (WFC27_Queue__c row : waiting) row.Status__c = 'done';
            if (!waiting.isEmpty()) update waiting;
        }
        if (!packetIds.isEmpty()) {
            List<WFC27_Packet__c> packets = [SELECT Id, Status__c FROM WFC27_Packet__c WHERE Id IN :packetIds];
            for (WFC27_Packet__c packet : packets) { packet.Status__c = 'completed'; packet.Completed_At__c = System.now(); }
            update packets;
            List<WFC27_Queue__c> queue = [SELECT Id, Status__c FROM WFC27_Queue__c WHERE Packet__c IN :packetIds AND Status__c = 'sent'];
            for (WFC27_Queue__c item : queue) item.Status__c = 'done';
            update queue;
        }
        for (Object value : errors) {
            Map<String,Object> item = (Map<String,Object>) value;
            String id = (String) item.get('packet_id');
            List<WFC27_Packet__c> packets = [SELECT Id, Status__c, Error__c FROM WFC27_Packet__c WHERE Id = :id LIMIT 1];
            if (!packets.isEmpty()) {
                Id failedPacketId = packets[0].Id;
                packets[0].Status__c = 'error'; packets[0].Error__c = String.valueOf(item.get('message')).left(32768); update packets;
                List<WFC27_Queue__c> queue = [SELECT Id, Status__c FROM WFC27_Queue__c WHERE Packet__c = :failedPacketId AND Status__c = 'sent'];
                for (WFC27_Queue__c row : queue) row.Status__c = 'error';
                if (!queue.isEmpty()) update queue;
            }
        }
    }

    private static Decimal decimalValue(Object value) {
        return value == null ? null : Decimal.valueOf(String.valueOf(value));
    }
    public class WFC27_TransportException extends Exception {}
}
