# Web Force Connect 27

WFC27 is a bidirectional text transport between Salesforce and WordPress. It targets a one-minute departure interval, but Salesforce Scheduled Apex can execute late, so the interval is not guaranteed. It installs a durable station on each side and shows every train trip, including empty trains. It does not inspect the payload or process business records. Data processing engines are separate future packages.

## The stations

The Salesforce unmanaged package adds `WFC27_Station__c` with an envelope ID, raw text payload, and status. The WordPress plugin creates the `wfc27_station` table with the same three fields (its `json` column stores raw text for backward compatibility). The local platform's standard record ID and timestamps remain available for administration. The envelope ID is unique across a station; repeated train delivery does not duplicate a staged item.

Administrators use the native Salesforce **Sync Packets** object or the WordPress **WFC27 → Sync Packets** list and detail screens. The Salesforce console does not duplicate the object list or creation form; its Recent Sync rows open native **WFC27 Recent Sync** records, whose related memberships link to each individual Sync Packet. Directly created Salesforce packets receive a ready status and envelope ID automatically. WordPress items can be edited before first pickup; offered, delivered, and inbound items remain visible for inspection. Each new WordPress sync links to its sent and received packets. Any text can be staged, including JSON and plain text; the train packet remains JSON and carries each payload as a string.

Transport statuses are `outbound_ready`, `outbound_pending` (WordPress while awaiting a receipt), `outbound_delivered`, `inbound_received`, and `inbound_acked`. An engine can later place outbound text into the local station and claim inbound text. WFC27 never updates posts or Salesforce business objects. A train records transport receipt; it is not a claim that a future engine processed the payload.

Salesforce schedules an authenticated HTTP exchange at a one-minute target interval. Its request contains up to the configured capacity of outbound envelopes and receipts for previously received WordPress envelopes. WordPress stores received envelopes before responding with their IDs, and includes its own outbound envelopes in that response. Salesforce stores those before acknowledging them on a later train. An empty train carries no envelope and still appears in both trip histories. Existing identity, binding, packet, and queue data from earlier versions are preserved but no longer read by WFC27.

The on-wire protocol is `wfc27.station.v2`:

```json
{
  "protocol": "wfc27.station.v2",
  "envelopes": [{"id": "stable-envelope-id", "payload": "any text, including JSON"}],
  "receipts": ["previously-received-envelope-id"],
  "capacity": 25,
  "train_state": "running"
}
```

The WordPress response uses the same protocol, envelopes, and receipts. `train_state` is optional in the response. The station payload is opaque to WFC27; it can represent any future object type. Business mapping, conflict handling, and content application belong to the future data processing engines.

## Deployment

The Salesforce source is under `salesforce/force-app/main/default`; its unmanaged package manifest is `salesforce/manifest/package.xml`. The WordPress plugin is under `wordpress/alphasys-web-force-connect-27`. WFC27 uses a Named Credential named `WFC27_WordPress` and a WordPress Application Password for an administrator or the receiver selected on the plugin settings page. The Salesforce console requires the `WFC27_Manage` permission. The batch capacity is 1–100 envelopes per train; the one-minute departure target is best effort under Scheduled Apex. A strict one-minute service level needs a scheduling mechanism outside Scheduled Apex.

The `legacy/` directory holds the earlier content-processing source for reference. It is not part of either current package. Existing deployed legacy data objects/tables are deliberately left in place for migration; deleting them would destroy data. The [station-first design](docs/station-first-architecture.md) describes the product boundary and future engine integration.

For local WordPress transport validation, run `wp eval-file tests/wp-station.php` from a disposable local site with the plugin active. The test refuses non-local environments and removes its own station items.
