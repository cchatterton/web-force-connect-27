# Station-first architecture

Status: implemented in WFC27 0.3.0. Data processing engines are separate future packages.

WFC27 moves opaque text between a Salesforce station and a WordPress station. It does not choose business records, interpret payloads, create posts, or update Salesforce business objects. A future data processing engine on each side may stage outbound text and consume inbound text, including JSON if that engine chooses.

Salesforce stores items in `WFC27_Station__c` with `Envelope_ID__c`, `Payload__c`, and `Status__c`. The object has a normal tab and record layout. WordPress stores them in its prefixed `wfc27_station` table with `envelope_id`, `json`, and `status`; the `json` column name is retained for compatibility but holds raw text. The WordPress admin has a separate Station Items list and detail screen. A station accepts JSON, plain text, empty text, or any other text within the platforms' storage limits.

The four transport statuses are `outbound_ready`, `outbound_delivered`, `inbound_received`, and `inbound_acked`. They do not describe business processing. The train trip log is separate and records empty trips as well as failed calls. Repeated delivery of the same envelope ID does not create another station item.

Salesforce initiates an authenticated train every minute. Its request includes ready Salesforce items and receipts for previously received WordPress items. The WordPress response acknowledges received Salesforce items and includes ready WordPress items. The next Salesforce train acknowledges those WordPress items. Each side stores a received item before acknowledging it.

The packet is JSON, but each payload is a string. Protocol `wfc27.station.v2` uses this shape:

```json
{
  "protocol": "wfc27.station.v2",
  "envelopes": [{"id": "stable-envelope-id", "payload": "uninterpreted text"}],
  "receipts": ["previously-received-envelope-id"],
  "capacity": 25,
  "train_state": "running"
}
```

The future engines own eligibility, field mapping, business identity, destination updates, deletion rules, and processing errors. They can use the station without changing WFC27's transport protocol.
