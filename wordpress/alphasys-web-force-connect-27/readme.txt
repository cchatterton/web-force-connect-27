=== AlphaSys Web Force Connect 27 ===
Contributors: alphasys
Tags: salesforce, integration, content, sync
Requires at least: 6.0
Tested up to: 7.1.1
Stable tag: 0.1.3
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receive Salesforce content packets as WordPress posts and postmeta.

== Description ==

WFC27 receives authenticated JSON packets from a configured Salesforce org. It stages each packet, applies its bound post and postmeta values, and returns completed results in later train responses. Salesforce-sourced values are protected from local editing; unbound WordPress fields remain local.

== Installation ==

Install and activate the plugin. Create an Application Password for a WordPress administrator. Optionally, select a different authorized user in WFC27 settings and create an Application Password for that user. Set the Salesforce outbound credential to the HTTPS endpoint shown on the WFC27 admin page. Register the destination post types before sending content. For reliable background processing, connect WP-Cron to a server scheduler.

== Changelog ==

= 0.1.3 =
Show the Sync Train Arriving countdown widget and the paused state sent by Salesforce. Remove duplicate last and next train timestamps.

= 0.1.2 =
Refresh the WordPress train heartbeat and queue counts automatically, with a 60-second progress bar that resets when a train arrives.

= 0.1.1 =
Use standard administrator access by default or an optionally selected receiver user; no custom capability or role is required.

= 0.1.0 =
* Initial packet receiver, inbound queue, identity map, and admin status page.

== External services ==

The plugin receives post fields, postmeta values, and Salesforce record IDs from the Salesforce org configured by the site administrator. It returns WordPress post and meta IDs, processing status, and deletion reports in the response to each call. It does not initiate calls to Salesforce.

The connection is to the administrator's own Salesforce org. Salesforce legal terms: https://www.salesforce.com/company/legal/customer-agreements/ . Salesforce privacy information: https://www.salesforce.com/company/legal/privacy/ .
