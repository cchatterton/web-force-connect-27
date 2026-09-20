public without sharing class WFC27_Scheduler implements Schedulable {
    public void execute(SchedulableContext context) { WFC27_Engine.tick(); }
    public static void start() {
        for (CronTrigger job : [SELECT Id FROM CronTrigger WHERE CronJobDetail.Name = 'WFC27 Train']) System.abortJob(job.Id);
        System.schedule('WFC27 Train', '0 * * * * ?', new WFC27_Scheduler());
    }
}
