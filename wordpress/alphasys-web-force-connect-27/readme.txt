=== AlphaSys Web Force Connect 27 ===
Contributors: alphasys
Tags: salesforce, integration, transport, json
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 0.3.9
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move opaque text between durable WordPress and Salesforce stations on a one-minute train.

== Description ==

WFC27 receives authenticated station trains from Salesforce. It stores inbound text in a WordPress station table, returns receipts, and can include locally staged outbound text in the same response. Payloads can contain JSON, plain text, or arbitrary text. It does not create or edit WordPress posts, process meta, or apply business rules. A separate data processing engine can later produce and consume station items. Train trips, including empty trains, are visible in the admin page.

== Installation ==

Install and activate the plugin. Create an Application Password for a WordPress administrator or choose a dedicated receiver user in WFC27 settings. Set the Salesforce Named Credential to the HTTPS endpoint shown on the WFC27 admin page. The Salesforce package creates the counterpart station and controls the one-minute train.

== Changelog ==

= 0.3.9 =
* Remove independent GitHub update checks and delegate updates to AlphaSys Update Controller. Keep Beta readiness and existing feature settings, package identity and domain restrictions.

= 0.3.7 =
Put the waiting packet count back inside each train widget without an awaiting-receipt count, and align the heartbeat styling across platforms.

= 0.3.6 =
Show the count of packets waiting for first pickup at each station, separate from the train countdown.

= 0.3.5 =
Keep train widgets focused on timing and controls; label trip counts explicitly as packets.

= 0.3.4 =
Track WordPress packets awaiting receipts separately from packets not yet picked up; reoffer unacknowledged packets with their stable IDs and prevent edits after first pickup.

= 0.3.3 =
Show how long a sync train is overdue instead of leaving the countdown at zero.

= 0.3.2 =
Show all four dashboard widgets across at standard desktop widths.

= 0.3.1 =
Use a four-cell dashboard, name the standalone list Sync Packets, and link each new sync to its sent and received packets.

= 0.3.0 =
Carry arbitrary raw text as a string payload within each train envelope. Add independent Station Items list and detail screens, a connection widget, and trip filter widget.

= 0.2.1 =
Add an administrator form to stage opaque outbound JSON for train tests.

= 0.2.0 =
Transport-only stations in both directions. Removed content processing and post/meta bindings from the plugin. Existing legacy tables remain untouched.

= 0.1.4 =
Show recent train trips, including empty packets, with time filters.

== External services ==

The configured Salesforce org initiates authenticated HTTP calls to this plugin's station endpoint. The request and response exchange JSON packets containing opaque text payloads and transport receipts. The plugin does not initiate calls to Salesforce. The connection is to the site administrator's own Salesforce org. Salesforce legal terms: https://www.salesforce.com/company/legal/customer-agreements/ . Salesforce privacy information: https://www.salesforce.com/company/legal/privacy/ .

== Managed updates ==

Install or activate AlphaSys Update Controller using the plugin row action. Update discovery is manual or scheduled by the controller; ordinary page rendering never checks GitHub. Feature operation does not require the controller. This release remains Beta. Earlier standalone updater instructions are superseded. Explicit controller installation downloads the official GitHub release; no feature settings or site inventory are sent. GitHub receives normal request metadata.

Service terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement
