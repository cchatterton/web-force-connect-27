public with sharing class WFC27_Config {
    public class Binding {
        public WFC27_Object_Binding__c objectRule;
        public List<WFC27_Field_Binding__c> fields = new List<WFC27_Field_Binding__c>();
    }

    public static List<Binding> activeBindings() {
        List<Binding> result = new List<Binding>();
        Map<Id, Binding> byId = new Map<Id, Binding>();
        for (WFC27_Object_Binding__c rule : [SELECT Id, Object_API_Name__c, WP_Post_Type__c,
                Eligible_Status__c, Draft_Retention_Days__c, Bin_Retention_Days__c, Scan_Cursor__c
                FROM WFC27_Object_Binding__c WHERE Active__c = true ORDER BY CreatedDate]) {
            Schema.SObjectType typeToken = Schema.getGlobalDescribe().get(rule.Object_API_Name__c);
            if (typeToken == null || !typeToken.getDescribe().isQueryable()) continue;
            Map<String, Schema.SObjectField> fieldMap = typeToken.getDescribe().fields.getMap();
            if (!fieldMap.containsKey('WFC27_Eligible__c') ||
                fieldMap.get('WFC27_Eligible__c').getDescribe().getType() != Schema.DisplayType.Boolean) continue;
            if (String.isBlank(rule.WP_Post_Type__c) || !Pattern.matches('[a-z0-9_\\-]{1,20}', rule.WP_Post_Type__c)) continue;
            if (rule.Eligible_Status__c != 'draft' && rule.Eligible_Status__c != 'publish' && rule.Eligible_Status__c != 'pending' && rule.Eligible_Status__c != 'private') continue;
            Binding item = new Binding();
            item.objectRule = rule;
            result.add(item);
            byId.put(rule.Id, item);
        }
        for (WFC27_Field_Binding__c field : [SELECT Object_Binding__c, Source_Field__c, Target_Type__c, WP_Key__c
                FROM WFC27_Field_Binding__c WHERE Active__c = true AND Object_Binding__c IN :byId.keySet()]) {
            Binding item = byId.get(field.Object_Binding__c);
            Map<String, Schema.SObjectField> fieldMap = Schema.getGlobalDescribe().get(item.objectRule.Object_API_Name__c).getDescribe().fields.getMap();
            if (!fieldMap.containsKey(field.Source_Field__c) || !fieldMap.get(field.Source_Field__c).getDescribe().isAccessible()) continue;
            if (field.Target_Type__c == 'post' && new Set<String>{'post_title','post_content','post_excerpt','post_name','post_status','post_date','menu_order','post_parent'}.contains(field.WP_Key__c)) {
                item.fields.add(field);
            } else if (field.Target_Type__c == 'meta' && !String.isBlank(field.WP_Key__c) && Pattern.matches('[A-Za-z0-9_\\-]{1,255}', field.WP_Key__c)) {
                item.fields.add(field);
            }
        }
        return result;
    }
}
