# Changelog

## Unreleased

- Schedule each one-minute Salesforce train as a one-time job and queue the next train when it runs. Salesforce does not accept a wildcard in the cron minute field.
- Show saved object bindings in a table and allow an object binding to be saved before its eligibility field exists. Active controls whether a ready binding participates in sync; missing eligibility still prevents sync.
- Reduce Salesforce object-list CPU time by reading EntityDefinition in pages. Poll transport status without reloading the object list.
- Explain that Salesforce's Field Name input should be `WFC27_Eligible`; Salesforce appends `__c` to produce the required API name.

## 0.1.2 - development

- Auto-refresh the WordPress heartbeat and queue counts, with a 60-second train progress bar that resets on each receipt.
- Auto-refresh the Salesforce transport status and show the same heartbeat progress.

## 0.1.1 - development

- Let WordPress administrators receive trains by default, with an optional selected receiver user.
- Remove the custom WordPress role and capability requirement.
- Use the query-style REST route for Salesforce callouts so sites without pretty REST URLs work.

## 0.1.0 - development

- Added Salesforce binding, queue, packet, identity, retention, scheduler, and admin console source.
- Added WordPress authenticated packet inbox, asynchronous processing, identity mapping, bound-field protection, and transport status page.
- Defined the asynchronous train and acknowledgement contract.
