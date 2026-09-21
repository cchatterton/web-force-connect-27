public without sharing class WFC27_Retention implements Schedulable, Database.Batchable<SObject> {
    public void execute(SchedulableContext context) { Database.executeBatch(new WFC27_Retention(), 200); }
    public Database.QueryLocator start(Database.BatchableContext context) {
        return Database.getQueryLocator('SELECT Id, Source_ID__c, Source_Object__c, WP_Post_ID__c, WP_Status__c, Status_Entered_At__c FROM WFC27_Map__c WHERE WP_Post_ID__c != null AND Status_Entered_At__c != null AND WP_Status__c IN (\'draft\',\'trash\')');
    }
    public void execute(Database.BatchableContext context, List<SObject> scope) {
        Map<String,WFC27_Object_Binding__c> rules = new Map<String,WFC27_Object_Binding__c>();
        for (WFC27_Object_Binding__c rule : [SELECT Object_API_Name__c, Draft_Retention_Days__c, Bin_Retention_Days__c FROM WFC27_Object_Binding__c]) rules.put(rule.Object_API_Name__c, rule);
        Set<String> ids = new Set<String>();
        for (SObject item : scope) ids.add(((WFC27_Map__c)item).Source_ID__c);
        Set<String> waiting = new Set<String>();
        for (WFC27_Queue__c row : [SELECT Source_ID__c FROM WFC27_Queue__c WHERE Source_ID__c IN :ids AND Status__c IN ('pending','sent')]) waiting.add(row.Source_ID__c);
        List<WFC27_Queue__c> queue = new List<WFC27_Queue__c>();
        for (SObject item : scope) {
            WFC27_Map__c mapRow = (WFC27_Map__c)item;
            WFC27_Object_Binding__c rule = rules.get(mapRow.Source_Object__c);
            if (rule == null || waiting.contains(mapRow.Source_ID__c)) continue;
            Integer days = mapRow.WP_Status__c == 'draft' ? (rule.Draft_Retention_Days__c == null ? null : rule.Draft_Retention_Days__c.intValue()) : (rule.Bin_Retention_Days__c == null ? null : rule.Bin_Retention_Days__c.intValue());
            if (days == null || mapRow.Status_Entered_At__c.addDays(days) > System.now()) continue;
            queue.add(new WFC27_Queue__c(Source_ID__c=mapRow.Source_ID__c, Source_Object__c=mapRow.Source_Object__c,
                Kind__c=mapRow.WP_Status__c == 'draft' ? 'demote' : 'delete', Status__c='pending'));
        }
        if (!queue.isEmpty()) insert queue;
    }
    public void finish(Database.BatchableContext context) {
        Datetime cutoff = System.now().addDays(-30);
        List<WFC27_Trip__c> expired = [SELECT Id FROM WFC27_Trip__c WHERE CreatedDate < :cutoff LIMIT 10000];
        if (!expired.isEmpty()) delete expired;
    }
    public static void startDaily() {
        for (CronTrigger job : [SELECT Id FROM CronTrigger WHERE CronJobDetail.Name = 'WFC27 Retention']) System.abortJob(job.Id);
        System.schedule('WFC27 Retention', '0 0 2 * * ?', new WFC27_Retention());
    }
}
