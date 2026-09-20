@IsTest
private class WFC27_TransportTest {
    private class ReceiptMock implements HttpCalloutMock {
        public HTTPResponse respond(HTTPRequest request) {
            System.assertEquals('POST', request.getMethod());
            Map<String,Object> payload = (Map<String,Object>)JSON.deserializeUntyped(request.getBody());
            Map<String,Object> summary = (Map<String,Object>)payload.get('summary');
            HttpResponse response = new HttpResponse();
            response.setStatusCode(200);
            response.setBody(JSON.serialize(new Map<String,Object>{
                'summary' => new Map<String,Object>{'received_packet_id' => summary.get('packet_id')},
                'completed' => new List<Object>(), 'deleted' => new List<Object>(), 'errors' => new List<Object>()
            }));
            return response;
        }
    }
    private class BadReceiptMock implements HttpCalloutMock {
        public HTTPResponse respond(HTTPRequest request) {
            HttpResponse response = new HttpResponse();
            response.setStatusCode(200);
            response.setBody('{"summary":{"received_packet_id":"wrong"},"completed":[],"deleted":[],"errors":[]}');
            return response;
        }
    }

    @IsTest static void receiptIsNotCompletion() {
        WFC27_Packet__c packet = new WFC27_Packet__c(Status__c='ready', Post_Count__c=1, Meta_Count__c=0);
        insert packet;
        packet.Payload__c = JSON.serialize(new Map<String,Object>{
            'summary' => new Map<String,Object>{'packet_id' => packet.Id, 'posts' => 1, 'postmeta' => 0},
            'posts' => new List<Object>{new Map<String,Object>{'sf_id' => '001000000000001AAA', 'post_type' => 'post', 'post_title' => 'Test'}},
            'postmeta' => new List<Object>()
        });
        update packet;
        Test.setMock(HttpCalloutMock.class, new ReceiptMock());
        Test.startTest();
        System.enqueueJob(new WFC27_Sender(packet.Id));
        Test.stopTest();
        System.assertEquals('received', [SELECT Status__c FROM WFC27_Packet__c WHERE Id = :packet.Id].Status__c);
    }

    @IsTest static void mismatchedReceiptFlagsError() {
        WFC27_Packet__c packet = new WFC27_Packet__c(Status__c='ready', Payload__c='{}');
        insert packet;
        packet.Payload__c = JSON.serialize(new Map<String,Object>{'summary' => new Map<String,Object>{'packet_id' => packet.Id},
            'posts' => new List<Object>(), 'postmeta' => new List<Object>()});
        update packet;
        Test.setMock(HttpCalloutMock.class, new BadReceiptMock());
        Test.startTest();
        System.enqueueJob(new WFC27_Sender(packet.Id));
        Test.stopTest();
        System.assertEquals('error', [SELECT Status__c FROM WFC27_Packet__c WHERE Id = :packet.Id].Status__c);
    }

    @IsTest static void emptyHeartbeatRunsWithoutPacket() {
        Test.setMock(HttpCalloutMock.class, new ReceiptMock());
        Test.startTest();
        WFC27_Engine.tick();
        Test.stopTest();
        System.assertEquals(0, [SELECT COUNT() FROM WFC27_Packet__c]);
        System.assertNotEquals(null, [SELECT Last_Tick_At__c FROM WFC27_Settings__c WHERE Name = 'Default' LIMIT 1].Last_Tick_At__c);
    }
}
