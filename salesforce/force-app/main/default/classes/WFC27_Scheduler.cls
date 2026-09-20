public without sharing class WFC27_Scheduler implements Schedulable {
    public void execute(SchedulableContext context) {
        scheduleNext();
        WFC27_Engine.tick();
    }
    public static void start() {
        for (CronTrigger job : [SELECT Id FROM CronTrigger WHERE CronJobDetail.Name LIKE 'WFC27 Train %' AND State = 'WAITING']) System.abortJob(job.Id);
        scheduleNext();
    }
    private static void scheduleNext() {
        Datetime nextRun = System.now().addMinutes(1);
        String name = 'WFC27 Train ' + nextRun.format('yyyyMMddHHmm');
        String cron = nextRun.format('0 m H d M ? yyyy');
        System.schedule(name, cron, new WFC27_Scheduler());
    }
}
