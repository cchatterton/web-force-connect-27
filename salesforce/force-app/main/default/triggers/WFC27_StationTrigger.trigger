trigger WFC27_StationTrigger on WFC27_Station__c (before insert, after insert) {
    if (Trigger.isBefore) {
        for (WFC27_Station__c row : Trigger.new) {
            if (String.isBlank(row.Status__c)) row.Status__c = 'outbound_ready';
        }
    }
    if (Trigger.isAfter) {
        List<WFC27_Station__c> identifiers = new List<WFC27_Station__c>();
        for (WFC27_Station__c row : Trigger.new) {
            if (String.isBlank(row.Envelope_ID__c)) identifiers.add(
                new WFC27_Station__c(Id=row.Id, Envelope_ID__c=String.valueOf(row.Id))
            );
        }
        if (!identifiers.isEmpty()) update identifiers;
    }
}
