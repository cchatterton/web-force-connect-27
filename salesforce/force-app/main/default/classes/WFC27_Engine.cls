public without sharing class WFC27_Engine {
    public static void tick() {
        WFC27_Settings__c settings = settings();
        settings = [SELECT Id, Last_Tick_At__c, Train_Paused__c FROM WFC27_Settings__c WHERE Id = :settings.Id FOR UPDATE];
        if (settings.Train_Paused__c) return;
        settings.Last_Tick_At__c = System.now();
        update settings;
        System.enqueueJob(new WFC27_Sender(null));
    }

    public static WFC27_Settings__c settings() {
        List<WFC27_Settings__c> rows = [SELECT Id, Name, Batch_Size__c, Last_Tick_At__c, Last_Error__c, Train_Paused__c
            FROM WFC27_Settings__c WHERE Name = 'Default' LIMIT 1];
        if (!rows.isEmpty()) return rows[0];
        WFC27_Settings__c row = new WFC27_Settings__c(Name='Default', Batch_Size__c=25);
        insert row;
        return row;
    }

    public static Id stageOutbound(String payload) {
        if (payload != null && payload.length() > 120000) throw new WFC27_StationException('Payload exceeds 120 KB.');
        WFC27_Station__c row = new WFC27_Station__c(Status__c='outbound_ready', Payload__c=payload);
        insert row;
        return row.Id;
    }
    public class WFC27_StationException extends Exception {}
}
