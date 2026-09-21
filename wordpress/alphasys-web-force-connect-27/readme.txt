=== AlphaSys Web Force Connect 27 ===
Contributors: alphasys
Tags: salesforce, integration, transport, json
Requires at least: 6.0
Tested up to: 7.1.1
Stable tag: 0.2.1
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move opaque JSON between durable WordPress and Salesforce stations on a one-minute train.

== Description ==

WFC27 receives authenticated station trains from Salesforce. It stores inbound JSON in a WordPress station table, returns receipts, and can include locally staged outbound JSON in the same response. It does not create or edit WordPress posts, process meta, or apply business rules. A separate data processing engine can later produce and consume station items. Train trips, including empty trains, are visible in the admin page.

== Installation ==

Install and activate the plugin. Create an Application Password for a WordPress administrator or choose a dedicated receiver user in WFC27 settings. Set the Salesforce Named Credential to the HTTPS endpoint shown on the WFC27 admin page. The Salesforce package creates the counterpart station and controls the one-minute train.

== Changelog ==

= 0.2.1 =
Add an administrator form to stage opaque outbound JSON for train tests.

= 0.2.0 =
Transport-only stations in both directions. Removed content processing and post/meta bindings from the plugin. Existing legacy tables remain untouched.

= 0.1.4 =
Show recent train trips, including empty packets, with time filters.

== External services ==

The configured Salesforce org initiates authenticated HTTP calls to this plugin's station endpoint. The request and response exchange opaque JSON envelopes and transport receipts. The plugin does not initiate calls to Salesforce. The connection is to the site administrator's own Salesforce org. Salesforce legal terms: https://www.salesforce.com/company/legal/customer-agreements/ . Salesforce privacy information: https://www.salesforce.com/company/legal/privacy/ .
