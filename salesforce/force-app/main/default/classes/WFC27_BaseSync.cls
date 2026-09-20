public without sharing class WFC27_BaseSync implements Database.Batchable<SObject> {
    private Id bindingId;
    public WFC27_BaseSync(Id bindingId) { this.bindingId = bindingId; }

    public Database.QueryLocator start(Database.BatchableContext context) {
        WFC27_Object_Binding__c rule = [SELECT Object_API_Name__c FROM WFC27_Object_Binding__c WHERE Id = :bindingId LIMIT 1];
        Schema.SObjectType typeToken = Schema.getGlobalDescribe().get(rule.Object_API_Name__c);
        if (typeToken == null || !typeToken.getDescribe().fields.getMap().containsKey('WFC27_Eligible__c')) throw new WFC27_BaseSyncException('Eligibility field is missing.');
        return Database.getQueryLocator('SELECT Id, LastModifiedDate, WFC27_Eligible__c FROM ' + rule.Object_API_Name__c);
    }

    public void execute(Database.BatchableContext context, List<SObject> scope) {
        WFC27_Object_Binding__c rule = [SELECT Object_API_Name__c FROM WFC27_Object_Binding__c WHERE Id = :bindingId LIMIT 1];
        Set<String> ids = new Set<String>();
        for (SObject record : scope) if ((Boolean) record.get('WFC27_Eligible__c')) ids.add(String.valueOf(record.get('Id')));
        Map<String,WFC27_Map__c> maps = new Map<String,WFC27_Map__c>();
        for (WFC27_Map__c row : [SELECT Id, Source_ID__c FROM WFC27_Map__c WHERE Source_ID__c IN :ids]) maps.put(row.Source_ID__c, row);
        Map<String,WFC27_Queue__c> waiting = new Map<String,WFC27_Queue__c>();
        for (WFC27_Queue__c row : [SELECT Id, Source_ID__c FROM WFC27_Queue__c WHERE Source_ID__c IN :ids AND Status__c = 'pending']) waiting.put(row.Source_ID__c, row);
        List<WFC27_Map__c> newMaps = new List<WFC27_Map__c>();
        List<WFC27_Queue__c> newQueue = new List<WFC27_Queue__c>();
        for (SObject record : scope) {
            if (!(Boolean) record.get('WFC27_Eligible__c')) continue;
            String id = String.valueOf(record.get('Id'));
            if (!maps.containsKey(id)) newMaps.add(new WFC27_Map__c(Source_ID__c=id, Source_Object__c=rule.Object_API_Name__c,
                Last_Source_Modified__c=(Datetime) record.get('LastModifiedDate'), Last_Eligible__c=true));
            if (!waiting.containsKey(id)) newQueue.add(new WFC27_Queue__c(Source_ID__c=id, Source_Object__c=rule.Object_API_Name__c,
                Kind__c='content', Status__c='pending', Source_Modified_At__c=(Datetime) record.get('LastModifiedDate')));
        }
        if (!newMaps.isEmpty()) upsert newMaps WFC27_Map__c.Source_ID__c;
        if (!newQueue.isEmpty()) insert newQueue;
    }

    public void finish(Database.BatchableContext context) {
        List<WFC27_Settings__c> rows = [SELECT Id, Last_Base_Sync_At__c FROM WFC27_Settings__c WHERE Name = 'Default' LIMIT 1];
        if (!rows.isEmpty()) { rows[0].Last_Base_Sync_At__c = System.now(); update rows; }
    }
    public class WFC27_BaseSyncException extends Exception {}
}
