# Web Force Connect 27

AlphaSys WFC27 is a Salesforce-to-WordPress content transport. Salesforce selects eligible records and sends bound fields; WordPress stages packets, creates or updates posts, and returns identity and processing results on later one-minute trains. Fields that Salesforce sends are protected in WordPress. Excerpt, featured image, AlphaBlocks, rows, and other fields stay local unless explicitly bound.

Version: 0.1.1 development build. The WordPress plugin was activated and smoke-tested locally. Salesforce metadata was deployed to the designated developer org on 2026-09-20 for further testing; this is not a released unmanaged package. The Salesforce minute train uses a one-time scheduled job that schedules its successor.

## Project declaration

| Item | Decision |
| --- | --- |
| Author | AlphaSys |
| Salesforce prefix | `WFC27_` |
| WordPress slug | `alphasys-web-force-connect-27` |
| Repository | `cchatterton/web-force-connect-27` |
| Salesforce distribution | Unmanaged package or reviewed metadata deployment; no automatic upgrades |
| WordPress distribution | Public GitHub release ZIP with native updater |
| Salesforce org | `orgfarm-83452b405d-dev-ed.develop.my.salesforce.com`, username `chris.48138b0da64f@agentforce.com` |
| Salesforce API | 66.0 source format; validate the target org's supported version before deployment |
| WordPress | 6.0+, PHP 8.1+ |
| Credentials | WordPress Application Password in Salesforce Named Credential; never in source |

Package-owned Salesforce objects are Object Binding, Field Binding, Queue, Packet, Map, Settings, and Deletion Acknowledgement. The customer-owned `WFC27_Eligible__c` checkbox or checkbox formula is created on selected source objects during setup, not bundled for a specific business object. A customer-owned post type must already be registered in WordPress. The package includes Apex services, scheduler, administrator console, permission set, and custom permission.

The scheduled transport services run without record sharing so eligible records from all owners can be evaluated. A source field binding is used only when that field is readable to the scheduling user; the administrator must grant the required object and field access. Only explicitly bound values are sent to WordPress. The setup console requires the `WFC27_Manage` custom permission.

## Contract

Content train from Salesforce:

```json
{
  "summary": {"packet_id":"a01000000000001AAA", "posts":1, "postmeta":1,
              "acknowledged_results":[], "acknowledged_deletions":[]},
  "posts": [{"sf_id":"a00000000000001AAA", "post_type":"course",
             "post_title":"Example course", "post_content":"Outline", "post_status":"draft"}],
  "postmeta": [{"sf_id":"a00000000000001AAA", "meta_key":"duration", "meta_value":"Two days"}],
  "deletes": []
}
```

WordPress receipt and prior completion response:

```json
{
  "summary": {"received_packet_id":"a01000000000001AAA"},
  "completed": [{"packet_id":"a01000000000000AAA",
                 "posts":[{"sf_id":"a00000000000001AAA", "wp_id":42,
                            "post_status":"draft", "status_entered_at":"2026-09-20 05:00:00"}],
                 "postmeta":[{"sf_id":"a00000000000001AAA", "meta_key":"duration", "wp_meta_id":87}]}],
  "deleted": [], "errors": []
}
```

An empty train has no packet ID and still carries acknowledgements and retrieves results. A successful HTTP response acknowledges receipt only. WordPress processing is asynchronous. Salesforce never resends a received packet automatically. A failed outbound call is recorded as an error for administrator review. WordPress uniquely stores the Salesforce packet ID, so an accidental duplicate HTTP delivery does not create two inbound packets.

## Setup

1. Install the WordPress plugin from its ZIP and activate it. Register the target post type first. Make WP-Cron reliable with a server scheduler; the WordPress inbox worker is scheduled every minute.
2. Create an Application Password for a WordPress administrator. The REST endpoint is shown on the WFC27 admin screen and accepts authenticated administrators by default. For a narrower connection, select another WordPress user under **WFC27 → Connection setup** and create an Application Password for that user. WFC27 does not require any custom WordPress role or capability.
3. Deploy the Salesforce source to the designated Developer Edition org after authenticating the CLI and confirming its username and instance URL. Assign `WFC27_Admin` to the administrator. Configure a Salesforce Named Credential named `WFC27_WordPress` with the WordPress site base URL and Basic authentication using the integration user's Application Password. The sender uses `/?rest_route=/wfc27/v1/train`, which also works on sites where pretty REST URLs are unavailable.
4. For each source object, add `WFC27_Eligible__c` as a checkbox or checkbox formula in Object Manager, or generate its deployment source with `python3 scripts/eligibility_field.py add Course__c`. Review and deploy the generated field in the customer org. The console shows objects with and without a valid flag.
5. Add an object binding and field bindings in the WFC27 console. Select the WordPress post type, status when eligible, and optional draft/bin retention days. Only activate once the target post type and field mappings are ready. If a field is mapped to `post_status`, its value overrides the fixed eligible status; it must be a supported WordPress status.
6. Start the one-minute and daily-retention schedules from the console. Confirm live transport with a test record, then use **Queue base sync** on each active object binding. The rolling scan also discovers eligible records; the batch job is the controlled full pass and can be used after binding changes. Monitor both consoles and trace a Salesforce ID through Map, Queue, Packet, and WordPress inbox.

Do not remove a customer's eligibility field when deactivating a binding. To permanently remove the field, generate and review a destructive deployment with `python3 scripts/eligibility_field.py remove Course__c`; that Salesforce action deletes field data.

## Operating rules

- One pending queue item per Salesforce source ID is refreshed by later changes. Sent packets are durable and never reused.
- A loss of eligibility sends a status demotion: published content becomes draft; draft becomes trash. The identity persists for later promotion.
- Draft and bin retention apply only to Salesforce-sourced posts. The daily Salesforce job queues draft-to-trash or final-delete instructions. WordPress does not choose retention dates.
- A human may permanently delete a Salesforce-sourced WordPress post. WordPress reports its Salesforce and WordPress IDs; Salesforce clears the pairing while retaining that the source version was already sent. A later Salesforce source change can create a fresh WordPress post.
- Salesforce-owned post fields and meta keys are locked in WordPress. Unbound WordPress content remains editable locally and does not sync back.
- The batch size is fixed and configurable from 1 to 100. Train frequency is one minute. The payload field imposes a 120,000-character application cap; oversized packets are marked error so the operator can reduce batch size or field lengths.

## Validation and current limits

PHP syntax, XML parsing, and Salesforce DX source conversion passed locally. A disposable WordPress 7.1.1 site verified activation, admin rendering, packet receipt, delayed completion, create/update of post and meta, bound-field protection, preservation of a local excerpt, local trash reporting, and the GitHub/Check for updates row links. Salesforce deployment `0Afaj00000l6WDTCA2` installed 65 components in the designated developer org with no component errors using `NoTestRun`. A separate `RunLocalTests` validation compiled the metadata and ran 11 tests without test failures, but failed the required 75% overall Apex coverage threshold at 46%. Named Credential setup, a full cross-system record journey, and an unmanaged package release remain outstanding. Do not use this build in production before those checks pass.

For another disposable local WordPress site, run `wp --path=/path/to/wordpress eval-file /path/to/web-force-connect-27/tests/wp-smoke.php` with the plugin active. The script refuses to run unless WordPress reports its environment type as `local`.

For repeatable train checks on a local site, run `wp --path=/path/to/wordpress eval-file /path/to/web-force-connect-27/tests/wp-train.php`. This test checks receipt, duplicate delivery, the scheduled inbox worker, a delayed completion response, and result acknowledgement. It removes the post, identity, and packet that it creates. On 2026-09-20, the plugin was installed and activated on the Local site **Codex Workbench** (WordPress 7.0, PHP 8.2.23); both the smoke and repeatable train tests passed. The local site is reachable only at `http://localhost:10384`, so Salesforce cannot call it until a reachable test URL and a WordPress integration credential are configured.

The scanner reads 1,000 records per active object per train in ID order. This means a one-to-five-minute content latency is a target only where a full scan completes within that window; larger objects take longer, particularly when formula eligibility changes without updating `LastModifiedDate`. The console should be used to watch queue age and train health. A later scale release should separate priority change detection from the rolling formula scan.

Salesforce outbound Apex callouts count against callout, async execution, scheduler, and storage limits, not inbound REST API request limits for the same org. The WordPress response is part of the same outbound HTTP exchange, not another Salesforce API request. Verify limits in each destination org. The one-minute Salesforce scheduled job may start late under platform load; it is not a hard real-time SLA.

## Development standards

This project follows `SF_unmanaged_package_standards.md`, `WP_plugin_standards.md`, `WP_plugin_github_update_standard.md`, `Codex_development_standards.md`, and `Branding_and_UX_standards.md` from `cchatterton/codex-standards`. The Salesforce package remains unmanaged; a later source change needs a reviewed metadata deployment rather than reinstalling a new unmanaged package as an upgrade.
