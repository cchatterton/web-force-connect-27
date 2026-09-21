public with sharing class WFC27_Admin {
    private static void requireManage() {
        if (!FeatureManagement.checkPermission('WFC27_Manage')) throw new AuraHandledException('WFC27 administrator permission required.');
    }

    @AuraEnabled
    public static Map<String,Object> status() {
        requireManage();
        List<WFC27_Settings__c> rows = [SELECT Batch_Size__c, Last_Tick_At__c, Last_Error__c, Train_Paused__c
            FROM WFC27_Settings__c WHERE Name = 'Default' LIMIT 1];
        WFC27_Settings__c settings = rows.isEmpty() ? null : rows[0];
        Integer waiting = [SELECT COUNT() FROM WFC27_Station__c WHERE Status__c = 'outbound_ready'];
        List<WFC27_Station__c> station = [SELECT Id, Envelope_ID__c, Status__c, Payload__c, CreatedDate
            FROM WFC27_Station__c ORDER BY CreatedDate DESC LIMIT 50];
        return new Map<String,Object>{
            'waiting' => waiting,
            'batchSize' => settings == null ? 25 : settings.Batch_Size__c,
            'lastTrain' => settings == null ? null : settings.Last_Tick_At__c,
            'trainPaused' => settings != null && settings.Train_Paused__c,
            'lastError' => settings == null ? null : settings.Last_Error__c,
            'station' => station
        };
    }

    @AuraEnabled
    public static List<WFC27_Trip__c> trips(String period, String day, Integer hour) {
        requireManage();
        Datetime finish = System.now();
        Datetime start = finish.addHours(period == 'day' ? -24 : -1);
        if ((period == 'date' || period == 'date_hour') && String.isNotBlank(day)) {
            Date selected = Date.valueOf(day);
            Integer selectedHour = period == 'date_hour' && hour != null && hour >= 0 && hour < 24 ? hour : 0;
            start = Datetime.newInstanceGmt(selected.year(), selected.month(), selected.day(), selectedHour, 0, 0);
            finish = period == 'date_hour' ? start.addHours(1) : start.addDays(1);
        }
        return [SELECT Id, CreatedDate, Sent_Count__c, Received_Count__c, Result__c, Error__c
            FROM WFC27_Trip__c WHERE CreatedDate >= :start AND CreatedDate < :finish AND Sent_Count__c != null ORDER BY CreatedDate DESC LIMIT 120];
    }

    @AuraEnabled
    public static Id stage(String json) {
        requireManage();
        return WFC27_Engine.stageOutbound(json);
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
        WFC27_Settings__c settings = WFC27_Engine.settings();
        settings.Train_Paused__c = false;
        update settings;
        WFC27_Scheduler.start();
    }

    @AuraEnabled
    public static void setTrainPaused(Boolean paused) {
        requireManage();
        if (paused == null) throw new AuraHandledException('Choose pause or play.');
        WFC27_Settings__c settings = WFC27_Engine.settings();
        if (paused && settings.Train_Paused__c) return;
        settings.Train_Paused__c = paused;
        if (!paused) settings.Last_Tick_At__c = System.now();
        update settings;
        if (paused) WFC27_Scheduler.stop();
        else WFC27_Scheduler.start();
        System.enqueueJob(new WFC27_Sender(null));
    }
}
