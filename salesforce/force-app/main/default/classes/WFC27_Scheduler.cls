public without sharing class WFC27_Scheduler implements Schedulable {
    public void execute(SchedulableContext context) {
        if (WFC27_Engine.settings().Train_Paused__c) return;
        scheduleNext();
        WFC27_Engine.tick();
    }
    public static void start() {
        stop();
        scheduleNext();
        WFC27_Retention.start();
    }
    public static void stop() {
        for (CronTrigger job : [SELECT Id FROM CronTrigger WHERE CronJobDetail.Name LIKE 'WFC27 Train %' AND State = 'WAITING']) System.abortJob(job.Id);
    }
    private static void scheduleNext() {
        Datetime nextRun = System.now().addMinutes(1);
        String name = 'WFC27 Train ' + nextRun.format('yyyyMMddHHmm');
        String cron = nextRun.format('0 m H d M ? yyyy');
        System.schedule(name, cron, new WFC27_Scheduler());
    }
}
