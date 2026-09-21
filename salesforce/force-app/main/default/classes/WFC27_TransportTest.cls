@IsTest
private class WFC27_TransportTest {
    private class StationMock implements HttpCalloutMock {
        public HttpResponse respond(HttpRequest request) {
            Map<String,Object> train = (Map<String,Object>) JSON.deserializeUntyped(request.getBody());
            System.assertEquals('wfc27.station.v2', train.get('protocol'));
            List<Object> envelopes = (List<Object>) train.get('envelopes');
            List<String> receipts = new List<String>();
            for (Object value : envelopes) receipts.add((String)((Map<String,Object>) value).get('id'));
            HttpResponse response = new HttpResponse();
            response.setStatusCode(200);
            response.setBody(JSON.serialize(new Map<String,Object>{'protocol' => 'wfc27.station.v2', 'receipts' => receipts,
                'envelopes' => new List<Object>{new Map<String,Object>{'id' => 'wp-test-1', 'payload' => 'jibberish from WordPress'}}}));
            return response;
        }
    }

    @IsTest static void outboundAndInboundAreStagedWithoutProcessing() {
        WFC27_Engine.settings();
        Id id = WFC27_Engine.stageOutbound('jibberish from Salesforce');
        Test.setMock(HttpCalloutMock.class, new StationMock());
        Test.startTest();
        System.enqueueJob(new WFC27_Sender(null));
        Test.stopTest();
        System.assertEquals('outbound_delivered', [SELECT Status__c FROM WFC27_Station__c WHERE Id = :id].Status__c);
        WFC27_Station__c inbound = [SELECT Status__c, Payload__c FROM WFC27_Station__c WHERE Envelope_ID__c = 'wp:wp-test-1' LIMIT 1];
        System.assertEquals('inbound_received', inbound.Status__c);
        System.assertEquals('jibberish from WordPress', inbound.Payload__c);
    }

    @IsTest static void directStationRecordIsReadyForTheTrain() {
        WFC27_Station__c row = new WFC27_Station__c(Payload__c='not JSON');
        insert row;
        row = [SELECT Envelope_ID__c, Status__c, Payload__c FROM WFC27_Station__c WHERE Id = :row.Id];
        System.assertEquals(String.valueOf(row.Id), row.Envelope_ID__c);
        System.assertEquals('outbound_ready', row.Status__c);
        System.assertEquals('not JSON', row.Payload__c);
    }

    @IsTest static void emptyTrainIsRecorded() {
        Test.setMock(HttpCalloutMock.class, new StationMock());
        Test.startTest();
        WFC27_Engine.tick();
        Test.stopTest();
        System.assertEquals(1, [SELECT COUNT() FROM WFC27_Trip__c]);
        System.assertEquals(0, [SELECT COUNT() FROM WFC27_Station__c WHERE Status__c = 'outbound_ready']);
    }

    @IsTest static void pausedSchedulerDoesNotTick() {
        WFC27_Settings__c settings = WFC27_Engine.settings();
        settings.Train_Paused__c = true;
        update settings;
        new WFC27_Scheduler().execute(null);
        System.assertEquals(null, [SELECT Last_Tick_At__c FROM WFC27_Settings__c WHERE Id = :settings.Id].Last_Tick_At__c);
    }

    @IsTest static void consoleAndScheduleExposeOnlyTransport() {
        Profile profile = [SELECT Id FROM Profile WHERE Id = :UserInfo.getProfileId() LIMIT 1];
        User operator = new User(Alias='wfc27', Email='wfc27@example.test', LastName='Station',
            Username='wfc27.' + String.valueOf(System.now().getTime()) + '@example.test',
            ProfileId=profile.Id, TimeZoneSidKey='Australia/Sydney', LocaleSidKey='en_AU',
            EmailEncodingKey='UTF-8', LanguageLocaleKey='en_US');
        insert operator;
        PermissionSet permission = [SELECT Id FROM PermissionSet WHERE Name = 'WFC27_Admin' LIMIT 1];
        insert new PermissionSetAssignment(AssigneeId=operator.Id, PermissionSetId=permission.Id);
        System.runAs(operator) {
            WFC27_Engine.settings();
            WFC27_Admin.setBatchSize(10);
            System.assertEquals(10, WFC27_Admin.status().get('batchSize'));
            System.assertNotEquals(null, WFC27_Admin.stage('{"test":true}'));
            System.assertEquals(0, WFC27_Admin.trips('hour', null, null).size());
            WFC27_Scheduler.start();
            WFC27_Scheduler.stop();
            WFC27_Admin.setTrainPaused(true);
            System.assertEquals(true, WFC27_Admin.status().get('trainPaused'));
        }
    }
}
