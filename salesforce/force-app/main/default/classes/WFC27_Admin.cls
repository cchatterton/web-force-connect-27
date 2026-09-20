public with sharing class WFC27_Admin {
    private static void requireManage() {
        if (!FeatureManagement.checkPermission('WFC27_Manage')) throw new AuraHandledException('WFC27 administrator permission required.');
    }

    @AuraEnabled
    public static List<Map<String,Object>> objects() {
        requireManage();
        Map<String,WFC27_Object_Binding__c> rules = new Map<String,WFC27_Object_Binding__c>();
        for (WFC27_Object_Binding__c rule : [SELECT Id, Object_API_Name__c, WP_Post_Type__c, Active__c,
                Eligible_Status__c, Draft_Retention_Days__c, Bin_Retention_Days__c FROM WFC27_Object_Binding__c]) rules.put(rule.Object_API_Name__c, rule);
        List<Map<String,Object>> result = new List<Map<String,Object>>();
        Integer offsetRows = 0;
        List<EntityDefinition> entities;
        do {
          entities = [SELECT QualifiedApiName, Label FROM EntityDefinition
                WHERE IsQueryable = true AND IsCustomizable = true AND IsDeprecatedAndHidden = false
                ORDER BY QualifiedApiName LIMIT 200 OFFSET :offsetRows];
          for (EntityDefinition entity : entities) {
            String api = entity.QualifiedApiName;
            if (api.startsWith('WFC27_')) continue;
            WFC27_Object_Binding__c rule = rules.get(api);
            Boolean hasFlag = false;
            if (rule != null) {
                Schema.SObjectType token = Schema.getGlobalDescribe().get(api);
                Schema.SObjectField flag = token == null ? null : token.getDescribe().fields.getMap().get('WFC27_Eligible__c');
                hasFlag = flag != null && flag.getDescribe().getType() == Schema.DisplayType.Boolean;
            }
            result.add(new Map<String,Object>{'api' => api, 'label' => entity.Label, 'hasFlag' => hasFlag,
                'bindingId' => rule == null ? null : rule.Id, 'postType' => rule == null ? null : rule.WP_Post_Type__c,
                'active' => rule != null && rule.Active__c,
                'eligibleStatus' => rule == null ? 'draft' : rule.Eligible_Status__c,
                'draftDays' => rule == null ? null : rule.Draft_Retention_Days__c,
                'binDays' => rule == null ? null : rule.Bin_Retention_Days__c});
          }
          offsetRows += entities.size();
        } while (entities.size() == 200 && offsetRows < 2000);
        return result;
    }

    @AuraEnabled
    public static List<Map<String,String>> fields(String objectApi) {
        requireManage();
        Schema.SObjectType token = Schema.getGlobalDescribe().get(objectApi);
        if (token == null) throw new AuraHandledException('Unknown object.');
        List<Map<String,String>> result = new List<Map<String,String>>();
        for (String api : token.getDescribe().fields.getMap().keySet()) {
            Schema.DescribeFieldResult field = token.getDescribe().fields.getMap().get(api).getDescribe();
            if (field.isAccessible()) result.add(new Map<String,String>{'api' => api, 'label' => field.getLabel(), 'type' => String.valueOf(field.getType())});
        }
        return result;
    }

    @AuraEnabled
    public static Id saveObject(String objectApi, String postType, String eligibleStatus, Boolean active, Integer draftDays, Integer binDays) {
        requireManage();
        Schema.SObjectType token = Schema.getGlobalDescribe().get(objectApi);
        if (token == null || !token.getDescribe().isQueryable() || !token.getDescribe().isUpdateable()) throw new AuraHandledException('Select an available Salesforce object.');
        if (String.isBlank(postType) || !Pattern.matches('[a-z0-9_\\-]{1,20}', postType)) throw new AuraHandledException('Enter a valid WordPress post type slug.');
        if (!new Set<String>{'draft','publish','pending','private'}.contains(eligibleStatus)) throw new AuraHandledException('Select a supported eligible status.');
        if ((draftDays != null && (draftDays < 0 || draftDays > 99999)) || (binDays != null && (binDays < 0 || binDays > 99999))) throw new AuraHandledException('Retention days must be between 0 and 99999.');
        List<WFC27_Object_Binding__c> rows = [SELECT Id, Name FROM WFC27_Object_Binding__c WHERE Object_API_Name__c = :objectApi LIMIT 1];
        WFC27_Object_Binding__c rule = rows.isEmpty() ? new WFC27_Object_Binding__c(Name=objectApi, Object_API_Name__c=objectApi) : rows[0];
        rule.WP_Post_Type__c = postType;
        rule.Eligible_Status__c = eligibleStatus;
        rule.Active__c = active;
        rule.Draft_Retention_Days__c = draftDays;
        rule.Bin_Retention_Days__c = binDays;
        upsert rule;
        return rule.Id;
    }

    @AuraEnabled
    public static Id saveField(Id bindingId, String sourceField, String targetType, String wpKey) {
        requireManage();
        WFC27_Object_Binding__c binding = [SELECT Id, Object_API_Name__c FROM WFC27_Object_Binding__c WHERE Id = :bindingId LIMIT 1];
        Schema.SObjectType token = Schema.getGlobalDescribe().get(binding.Object_API_Name__c);
        if (token == null || !token.getDescribe().fields.getMap().containsKey(sourceField)) throw new AuraHandledException('Unknown Salesforce field.');
        if (targetType != 'post' && targetType != 'meta') throw new AuraHandledException('Target must be post or meta.');
        if (targetType == 'post' && !new Set<String>{'post_title','post_content','post_excerpt','post_name','post_status','post_date','menu_order','post_parent'}.contains(wpKey)) throw new AuraHandledException('Unsupported WordPress post field.');
        if (targetType == 'meta' && (String.isBlank(wpKey) || !Pattern.matches('[A-Za-z0-9_\\-]{1,255}', wpKey))) throw new AuraHandledException('Invalid WordPress meta key.');
        List<WFC27_Field_Binding__c> existing = [SELECT Id FROM WFC27_Field_Binding__c WHERE Object_Binding__c = :bindingId AND Target_Type__c = :targetType AND WP_Key__c = :wpKey LIMIT 1];
        WFC27_Field_Binding__c row = existing.isEmpty() ? new WFC27_Field_Binding__c(Object_Binding__c=bindingId) : existing[0];
        row.Source_Field__c = sourceField;
        row.Target_Type__c = targetType;
        row.WP_Key__c = wpKey;
        row.Active__c = true;
        upsert row;
        return row.Id;
    }

    @AuraEnabled
    public static List<WFC27_Field_Binding__c> fieldRules(Id bindingId) {
        requireManage();
        return [SELECT Id, Source_Field__c, Target_Type__c, WP_Key__c, Active__c FROM WFC27_Field_Binding__c
            WHERE Object_Binding__c = :bindingId ORDER BY Target_Type__c, WP_Key__c];
    }

    @AuraEnabled
    public static void removeField(Id fieldRuleId) {
        requireManage();
        WFC27_Field_Binding__c row = [SELECT Id FROM WFC27_Field_Binding__c WHERE Id = :fieldRuleId LIMIT 1];
        delete row;
    }

    @AuraEnabled
    public static Map<String,Object> status() {
        requireManage();
        List<WFC27_Settings__c> settings = [SELECT Batch_Size__c, Last_Tick_At__c, Last_Error__c FROM WFC27_Settings__c WHERE Name = 'Default' LIMIT 1];
        Integer waiting = [SELECT COUNT() FROM WFC27_Queue__c WHERE Status__c = 'pending'];
        Integer errors = [SELECT COUNT() FROM WFC27_Packet__c WHERE Status__c = 'error'];
        Long totalWaitMs = 0;
        Integer waitSamples = 0;
        for (WFC27_Queue__c item : [SELECT Source_Modified_At__c, Packet__r.Completed_At__c FROM WFC27_Queue__c
                WHERE Status__c = 'done' AND Kind__c = 'content' AND Source_Modified_At__c != null AND Packet__r.Completed_At__c != null
                ORDER BY CreatedDate DESC LIMIT 100]) {
            totalWaitMs += item.Packet__r.Completed_At__c.getTime() - item.Source_Modified_At__c.getTime();
            waitSamples++;
        }
        List<WFC27_Packet__c> packets = [SELECT Id, Status__c, Sent_At__c, Completed_At__c, Post_Count__c, Meta_Count__c, Error__c
            FROM WFC27_Packet__c ORDER BY CreatedDate DESC LIMIT 20];
        return new Map<String,Object>{'waiting' => waiting, 'errors' => errors, 'batchSize' => settings.isEmpty() ? 25 : settings[0].Batch_Size__c,
            'lastTrain' => settings.isEmpty() ? null : settings[0].Last_Tick_At__c,
            'lastError' => settings.isEmpty() ? null : settings[0].Last_Error__c,
            'averageWaitSeconds' => waitSamples == 0 ? null : Math.round((Decimal)totalWaitMs / waitSamples / 1000),
            'nextExpected' => settings.isEmpty() || settings[0].Last_Tick_At__c == null ? null : settings[0].Last_Tick_At__c.addMinutes(1),
            'packets' => packets};
    }

    @AuraEnabled
    public static void setBatchSize(Integer size) {
        requireManage();
        if (size == null || size < 1 || size > 100) throw new AuraHandledException('Batch size must be from 1 to 100.');
        WFC27_Settings__c row = WFC27_Engine.settings();
        row.Batch_Size__c = size;
        update row;
    }

    @AuraEnabled
    public static void startSchedules() {
        requireManage();
        WFC27_Scheduler.start();
        WFC27_Retention.startDaily();
    }

    @AuraEnabled
    public static Id startBaseSync(Id bindingId) {
        requireManage();
        WFC27_Object_Binding__c rule = [SELECT Id, Active__c, Object_API_Name__c FROM WFC27_Object_Binding__c WHERE Id = :bindingId LIMIT 1];
        if (!rule.Active__c) throw new AuraHandledException('Activate the object binding first.');
        Schema.SObjectType token = Schema.getGlobalDescribe().get(rule.Object_API_Name__c);
        Schema.SObjectField flag = token == null ? null : token.getDescribe().fields.getMap().get('WFC27_Eligible__c');
        if (flag == null || flag.getDescribe().getType() != Schema.DisplayType.Boolean) throw new AuraHandledException('Add the WFC27_Eligible__c checkbox or checkbox formula before starting a base sync.');
        return Database.executeBatch(new WFC27_BaseSync(bindingId), 100);
    }

    @AuraEnabled
    public static Map<String,Object> journey(String sourceId) {
        requireManage();
        if (String.isBlank(sourceId) || !Pattern.matches('[A-Za-z0-9]{15,18}', sourceId)) throw new AuraHandledException('Enter a Salesforce record ID.');
        List<WFC27_Map__c> maps = [SELECT Source_ID__c, Source_Object__c, WP_Post_ID__c, WP_Status__c,
            Last_Source_Modified__c, Last_Eligible__c, Last_Sent_At__c FROM WFC27_Map__c WHERE Source_ID__c = :sourceId LIMIT 1];
        List<WFC27_Queue__c> queue = [SELECT Id, Kind__c, Status__c, Packet__c, CreatedDate FROM WFC27_Queue__c
            WHERE Source_ID__c = :sourceId ORDER BY CreatedDate DESC LIMIT 25];
        return new Map<String,Object>{'identity' => maps.isEmpty() ? null : maps[0], 'queue' => queue};
    }
}
