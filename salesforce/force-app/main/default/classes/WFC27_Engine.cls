public without sharing class WFC27_Engine {
    public static void tick() {
        WFC27_Settings__c settings = settings();
        settings = [SELECT Id, Batch_Size__c, Last_Tick_At__c, Last_Scan_At__c, Train_Paused__c FROM WFC27_Settings__c WHERE Id = :settings.Id FOR UPDATE];
        if (settings.Train_Paused__c) return;
        List<WFC27_Config.Binding> bindings = WFC27_Config.activeBindings();
        Integer scanPage = bindings.isEmpty() ? 1000 : Math.max(1, Math.min(1000, 4000 / bindings.size()));
        for (WFC27_Config.Binding binding : bindings) scan(binding, scanPage);
        settings.Last_Tick_At__c = System.now();
        settings.Last_Scan_At__c = System.now();
        upsert settings;
        dispatch(Math.max(1, Math.min(100, settings.Batch_Size__c == null ? 25 : settings.Batch_Size__c.intValue())));
    }

    public static WFC27_Settings__c settings() {
        List<WFC27_Settings__c> rows = [SELECT Id, Name, Batch_Size__c, Last_Tick_At__c, Last_Scan_At__c, Last_Base_Sync_At__c, Last_Error__c, Train_Paused__c FROM WFC27_Settings__c WHERE Name = 'Default' LIMIT 1];
        if (!rows.isEmpty()) return rows[0];
        WFC27_Settings__c row = new WFC27_Settings__c(Name='Default', Batch_Size__c=25);
        insert row;
        return row;
    }

    private static void scan(WFC27_Config.Binding binding, Integer scanPage) {
        String api = binding.objectRule.Object_API_Name__c;
        Schema.DescribeSObjectResult description = Schema.getGlobalDescribe().get(api).getDescribe();
        Map<String, Schema.SObjectField> available = description.fields.getMap();
        List<String> fields = new List<String>{'Id','LastModifiedDate','WFC27_Eligible__c'};
        for (WFC27_Field_Binding__c rule : binding.fields) if (!fields.contains(rule.Source_Field__c)) fields.add(rule.Source_Field__c);
        String cursor = binding.objectRule.Scan_Cursor__c;
        String soql = 'SELECT ' + String.join(fields, ',') + ' FROM ' + api;
        if (!String.isBlank(cursor)) soql += ' WHERE Id > :cursor';
        soql += ' ORDER BY Id LIMIT ' + scanPage;
        List<SObject> records = Database.query(soql);
        if (records.isEmpty() && !String.isBlank(cursor)) {
            binding.objectRule.Scan_Cursor__c = null;
            update binding.objectRule;
            return;
        }
        Set<String> ids = new Set<String>();
        for (SObject record : records) ids.add(String.valueOf(record.get('Id')));
        Map<String, WFC27_Map__c> maps = new Map<String, WFC27_Map__c>();
        for (WFC27_Map__c row : [SELECT Id, Source_ID__c, Source_Object__c, WP_Post_ID__c, WP_Status__c, Status_Entered_At__c,
                Last_Source_Modified__c, Last_Eligible__c, Last_Sent_At__c FROM WFC27_Map__c WHERE Source_ID__c IN :ids]) maps.put(row.Source_ID__c, row);
        Map<String, WFC27_Queue__c> pending = new Map<String, WFC27_Queue__c>();
        for (WFC27_Queue__c row : [SELECT Id, Source_ID__c, Source_Modified_At__c, Status__c, Kind__c
                FROM WFC27_Queue__c WHERE Source_ID__c IN :ids AND Status__c = 'pending']) pending.put(row.Source_ID__c, row);
        List<WFC27_Map__c> mapWrites = new List<WFC27_Map__c>();
        List<WFC27_Queue__c> queueWrites = new List<WFC27_Queue__c>();
        for (SObject record : records) {
            String id = String.valueOf(record.get('Id'));
            Boolean eligible = (Boolean) record.get('WFC27_Eligible__c');
            Datetime modified = (Datetime) record.get('LastModifiedDate');
            WFC27_Map__c mapRow = maps.get(id);
            if (!eligible && mapRow == null) continue;
            Boolean changed = mapRow == null || mapRow.Last_Eligible__c != eligible ||
                (eligible && mapRow.Last_Source_Modified__c != modified);
            if (!changed) continue;
            if (mapRow == null) mapRow = new WFC27_Map__c(Source_ID__c=id, Source_Object__c=api);
            mapRow.Last_Eligible__c = eligible;
            mapRow.Last_Source_Modified__c = modified;
            mapWrites.add(mapRow);
            WFC27_Queue__c queueRow = pending.get(id);
            if (queueRow == null) queueRow = new WFC27_Queue__c(Source_ID__c=id, Source_Object__c=api);
            queueRow.Kind__c = eligible ? 'content' : 'demote';
            queueRow.Status__c = 'pending';
            queueRow.Source_Modified_At__c = modified;
            queueWrites.add(queueRow);
        }
        if (!mapWrites.isEmpty()) upsert mapWrites WFC27_Map__c.Source_ID__c;
        if (!queueWrites.isEmpty()) upsert queueWrites;
        binding.objectRule.Scan_Cursor__c = records.size() < scanPage ? null : String.valueOf(records[records.size()-1].get('Id'));
        update binding.objectRule;
    }

    private static void dispatch(Integer batchSize) {
        List<WFC27_Queue__c> queue = [SELECT Id, Source_ID__c, Source_Object__c, Kind__c, Status__c
            FROM WFC27_Queue__c WHERE Status__c = 'pending' LIMIT :batchSize FOR UPDATE];
        if (queue.isEmpty()) {
            System.enqueueJob(new WFC27_Sender(null));
            return;
        }
        Map<String, WFC27_Config.Binding> bindings = new Map<String, WFC27_Config.Binding>();
        for (WFC27_Config.Binding binding : WFC27_Config.activeBindings()) bindings.put(binding.objectRule.Object_API_Name__c, binding);
        Map<String, List<String>> idsByObject = new Map<String, List<String>>();
        Set<String> allIds = new Set<String>();
        for (WFC27_Queue__c item : queue) {
            if (!idsByObject.containsKey(item.Source_Object__c)) idsByObject.put(item.Source_Object__c, new List<String>());
            idsByObject.get(item.Source_Object__c).add(item.Source_ID__c);
            allIds.add(item.Source_ID__c);
        }
        Map<String, WFC27_Map__c> maps = new Map<String, WFC27_Map__c>();
        for (WFC27_Map__c row : [SELECT Id, Source_ID__c, WP_Post_ID__c, WP_Status__c FROM WFC27_Map__c WHERE Source_ID__c IN :allIds]) maps.put(row.Source_ID__c, row);
        Map<String, SObject> source = new Map<String, SObject>();
        for (String api : idsByObject.keySet()) {
            if (!bindings.containsKey(api)) continue;
            List<String> selectFields = new List<String>{'Id','WFC27_Eligible__c'};
            for (WFC27_Field_Binding__c rule : bindings.get(api).fields) if (!selectFields.contains(rule.Source_Field__c)) selectFields.add(rule.Source_Field__c);
            List<String> ids = idsByObject.get(api);
            for (SObject record : Database.query('SELECT ' + String.join(selectFields, ',') + ' FROM ' + api + ' WHERE Id IN :ids')) source.put(String.valueOf(record.get('Id')), record);
        }
        List<Object> posts = new List<Object>();
        List<Object> postmeta = new List<Object>();
        List<Object> deletes = new List<Object>();
        List<WFC27_Queue__c> sent = new List<WFC27_Queue__c>();
        for (WFC27_Queue__c item : queue) {
            WFC27_Config.Binding binding = bindings.get(item.Source_Object__c);
            WFC27_Map__c mapRow = maps.get(item.Source_ID__c);
            SObject record = source.get(item.Source_ID__c);
            if (binding == null || record == null || mapRow == null) { item.Status__c = 'error'; sent.add(item); continue; }
            Map<String, Object> post = new Map<String, Object>{'sf_id' => item.Source_ID__c, 'post_type' => binding.objectRule.WP_Post_Type__c};
            if (item.Kind__c == 'demote') {
                if (mapRow.WP_Post_ID__c == null) { item.Status__c = 'done'; sent.add(item); continue; }
                post.put('post_status', mapRow.WP_Status__c == 'draft' ? 'trash' : 'draft');
            } else if (item.Kind__c == 'delete') {
                deletes.add(new Map<String,Object>{'sf_id' => item.Source_ID__c});
                item.Status__c = 'sent'; sent.add(item); continue;
            } else {
                post.put('post_status', binding.objectRule.Eligible_Status__c);
                for (WFC27_Field_Binding__c rule : binding.fields) {
                    Object value = record.get(rule.Source_Field__c);
                    if (rule.Target_Type__c == 'post' && rule.WP_Key__c == 'post_date' && value instanceof Datetime) value = ((Datetime)value).formatGmt('yyyy-MM-dd HH:mm:ss');
                    if (rule.Target_Type__c == 'post') post.put(rule.WP_Key__c, value);
                    if (rule.Target_Type__c == 'meta') postmeta.add(new Map<String,Object>{'sf_id' => item.Source_ID__c, 'meta_key' => rule.WP_Key__c, 'meta_value' => value});
                }
            }
            posts.add(post);
            item.Status__c = 'sent'; sent.add(item);
        }
        if (posts.isEmpty() && postmeta.isEmpty() && deletes.isEmpty()) { update sent; System.enqueueJob(new WFC27_Sender(null)); return; }
        WFC27_Packet__c packet = new WFC27_Packet__c(Status__c='ready', Post_Count__c=posts.size(), Meta_Count__c=postmeta.size());
        insert packet;
        Map<String,Object> summary = new Map<String,Object>{'packet_id' => packet.Id, 'posts' => posts.size(), 'postmeta' => postmeta.size()};
        String payload = JSON.serialize(new Map<String,Object>{'summary' => summary, 'posts' => posts, 'postmeta' => postmeta, 'deletes' => deletes});
        if (payload.length() > 120000) {
            packet.Status__c = 'error';
            packet.Error__c = 'Packet exceeds the Salesforce payload field. Reduce batch size or source field length.';
            update packet;
            for (WFC27_Queue__c item : sent) item.Status__c = 'error';
            update sent;
            return;
        }
        packet.Payload__c = payload;
        update packet;
        for (WFC27_Queue__c item : sent) if (item.Status__c == 'sent') item.Packet__c = packet.Id;
        update sent;
        System.enqueueJob(new WFC27_Sender(packet.Id));
    }
}
