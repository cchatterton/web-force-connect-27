public without sharing class WFC27_Sender implements Queueable, Database.AllowsCallouts {
    public WFC27_Sender(Id ignored) {}

    public void execute(QueueableContext context) {
        WFC27_Settings__c settings = WFC27_Engine.settings();
        Integer capacity = Math.max(1, Math.min(100, settings.Batch_Size__c == null ? 25 : settings.Batch_Size__c.intValue()));
        List<WFC27_Station__c> outbound = [SELECT Id, Envelope_ID__c, Payload__c FROM WFC27_Station__c
            WHERE Status__c = 'outbound_ready' ORDER BY CreatedDate ASC LIMIT :capacity];
        List<WFC27_Station__c> inbound = [SELECT Id, Envelope_ID__c FROM WFC27_Station__c
            WHERE Status__c = 'inbound_received' ORDER BY CreatedDate ASC LIMIT 100];
        List<Object> envelopes = new List<Object>();
        List<WFC27_Station__c> sentOutbound = new List<WFC27_Station__c>();
        List<String> receipts = new List<String>();
        Integer payloadSize = 0;
        for (WFC27_Station__c row : outbound) {
            payloadSize += row.Payload__c.length();
            if (payloadSize > 1800000) break;
            envelopes.add(new Map<String,Object>{'id' => String.valueOf(row.Id), 'payload' => JSON.deserializeUntyped(row.Payload__c)});
            sentOutbound.add(row);
        }
        for (WFC27_Station__c row : inbound) receipts.add(row.Envelope_ID__c.removeStart('wp:'));
        HttpRequest request = new HttpRequest();
        request.setEndpoint('callout:WFC27_WordPress/?rest_route=/wfc27/v1/train');
        request.setMethod('POST');
        request.setHeader('Content-Type', 'application/json');
        request.setTimeout(30000);
        request.setBody(JSON.serialize(new Map<String,Object>{'protocol' => 'wfc27.station.v1', 'envelopes' => envelopes,
            'receipts' => receipts, 'train_state' => settings.Train_Paused__c ? 'paused' : 'running', 'capacity' => capacity}));
        try {
            HttpResponse response = new Http().send(request);
            if (response.getStatusCode() < 200 || response.getStatusCode() > 299) throw new WFC27_TransportException('WordPress returned HTTP ' + response.getStatusCode());
            Map<String,Object> result = (Map<String,Object>) JSON.deserializeUntyped(response.getBody());
            if ((String) result.get('protocol') != 'wfc27.station.v1') throw new WFC27_TransportException('Unexpected station protocol.');
            List<Object> acknowledged = (List<Object>) result.get('receipts');
            List<Object> arrived = (List<Object>) result.get('envelopes');
            if (acknowledged == null || arrived == null || arrived.size() > capacity) throw new WFC27_TransportException('Invalid station response.');
            Set<String> acknowledgedIds = new Set<String>();
            for (Object value : acknowledged) acknowledgedIds.add(String.valueOf(value));
            for (WFC27_Station__c row : sentOutbound) if (acknowledgedIds.contains(String.valueOf(row.Id))) {
                row.Status__c = 'outbound_delivered';
            }
            List<WFC27_Station__c> writes = new List<WFC27_Station__c>();
            for (Object value : arrived) {
                Map<String,Object> envelope = (Map<String,Object>) value;
                String id = String.valueOf(envelope.get('id'));
                if (String.isBlank(id) || id.length() > 96 || envelope.get('payload') == null) throw new WFC27_TransportException('Invalid inbound envelope.');
                String json = JSON.serialize(envelope.get('payload'));
                if (json.length() > 120000) throw new WFC27_TransportException('Inbound JSON is too large.');
                writes.add(new WFC27_Station__c(Envelope_ID__c='wp:' + id, Status__c='inbound_received', Payload__c=json));
            }
            for (WFC27_Station__c row : writes) {
                try { insert row; }
                catch (DmlException duplicate) {
                    if (!duplicate.getMessage().contains('DUPLICATE_VALUE')) throw duplicate;
                }
            }
            for (WFC27_Station__c row : inbound) row.Status__c = 'inbound_acked';
            if (!sentOutbound.isEmpty()) update sentOutbound;
            if (!inbound.isEmpty()) update inbound;
            if (settings.Last_Error__c != null) { settings.Last_Error__c = null; update settings; }
            insert new WFC27_Trip__c(Sent_Count__c=sentOutbound.size(), Received_Count__c=arrived.size(), Result__c='Delivered');
        } catch (Exception error) {
            settings.Last_Error__c = error.getMessage().left(32768);
            update settings;
            insert new WFC27_Trip__c(Sent_Count__c=sentOutbound.size(), Received_Count__c=0, Result__c='Failed', Error__c=error.getMessage().left(255));
        }
    }
    public class WFC27_TransportException extends Exception {}
}
