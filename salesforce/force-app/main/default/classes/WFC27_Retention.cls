public without sharing class WFC27_Retention implements Schedulable {
    public void execute(SchedulableContext context) {
        cleanup();
    }

    public static void start() {
        stop();
        System.schedule('WFC27 Retention', '0 0 2 * * ?', new WFC27_Retention());
    }

    public static void stop() {
        for (CronTrigger job : [SELECT Id FROM CronTrigger WHERE CronJobDetail.Name = 'WFC27 Retention' AND State = 'WAITING']) {
            System.abortJob(job.Id);
        }
    }

    public static Integer cleanup() {
        WFC27_Settings__c settings = WFC27_Engine.settings();
        Integer emptyDays = settings.Empty_Sync_Retention_Days__c == null ? 7 : settings.Empty_Sync_Retention_Days__c.intValue();
        Integer activeDays = settings.Packet_Sync_Retention_Days__c == null ? 90 : settings.Packet_Sync_Retention_Days__c.intValue();
        Integer failedDays = settings.Failed_Sync_Retention_Days__c == null ? 365 : settings.Failed_Sync_Retention_Days__c.intValue();
        Datetime emptyCutoff = System.now().addDays(-emptyDays);
        Datetime activeCutoff = System.now().addDays(-activeDays);
        Datetime failedCutoff = System.now().addDays(-failedDays);
        List<WFC27_Trip__c> expired = [
            SELECT Id FROM WFC27_Trip__c
            WHERE (Result__c = 'Delivered' AND Sent_Count__c = 0 AND Received_Count__c = 0 AND CreatedDate < :emptyCutoff)
               OR (Result__c = 'Delivered' AND (Sent_Count__c > 0 OR Received_Count__c > 0) AND CreatedDate < :activeCutoff)
               OR (Result__c = 'Failed' AND CreatedDate < :failedCutoff)
            ORDER BY CreatedDate ASC
            LIMIT 10000
        ];
        delete expired;
        return expired.size();
    }
}
