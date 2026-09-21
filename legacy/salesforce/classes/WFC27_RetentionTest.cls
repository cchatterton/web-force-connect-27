@IsTest
private class WFC27_RetentionTest {
    @IsTest static void queuesOnlyExpiredMappedPostsOnce() {
        insert new WFC27_Object_Binding__c(Name='Account', Object_API_Name__c='Account', WP_Post_Type__c='post',
            Eligible_Status__c='draft', Active__c=false, Draft_Retention_Days__c=2, Bin_Retention_Days__c=7);
        WFC27_Map__c expiredDraft = new WFC27_Map__c(Source_ID__c='001000000000001AAA', Source_Object__c='Account',
            WP_Post_ID__c=101, WP_Status__c='draft', Status_Entered_At__c=System.now().addDays(-3));
        WFC27_Map__c freshBin = new WFC27_Map__c(Source_ID__c='001000000000002AAA', Source_Object__c='Account',
            WP_Post_ID__c=102, WP_Status__c='trash', Status_Entered_At__c=System.now().addDays(-3));
        insert new List<WFC27_Map__c>{expiredDraft, freshBin};
        List<SObject> scope = new List<SObject>{expiredDraft, freshBin};
        WFC27_Retention job = new WFC27_Retention();
        job.execute(null, scope);
        job.execute(null, scope);
        List<WFC27_Queue__c> queue = [SELECT Kind__c, Source_ID__c FROM WFC27_Queue__c WHERE Status__c = 'pending'];
        System.assertEquals(1, queue.size());
        System.assertEquals('demote', queue[0].Kind__c);
        System.assertEquals(expiredDraft.Source_ID__c, queue[0].Source_ID__c);
    }

    @IsTest static void queuesBinDeletionAfterRetention() {
        insert new WFC27_Object_Binding__c(Name='Course', Object_API_Name__c='Course__c', WP_Post_Type__c='course',
            Eligible_Status__c='draft', Active__c=false, Bin_Retention_Days__c=1);
        WFC27_Map__c expiredBin = new WFC27_Map__c(Source_ID__c='a00000000000001AAA', Source_Object__c='Course__c',
            WP_Post_ID__c=103, WP_Status__c='trash', Status_Entered_At__c=System.now().addDays(-2));
        insert expiredBin;
        new WFC27_Retention().execute(null, new List<SObject>{expiredBin});
        System.assertEquals('delete', [SELECT Kind__c FROM WFC27_Queue__c WHERE Source_ID__c = :expiredBin.Source_ID__c LIMIT 1].Kind__c);
    }
}
