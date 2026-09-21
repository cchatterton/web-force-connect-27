# Station-first architecture proposal

Status: design decision; current runtime is unchanged.

## Product boundary

WFC27 is transport. It owns an authenticated one-minute train, durable stations on both sides, receipt acknowledgements, pause/play, retry of *unreceived transport deliveries*, and trip history. It does not query business objects, choose eligible records, map fields, build post/meta JSON, write business records, or maintain business identity maps.

Build a separate Salesforce data processing engine and a separate WordPress data processing engine. Each can publish an outbound envelope into its local station and consume an inbound envelope from that station. The engines own object selection, rules, JSON creation and interpretation, destination upserts, deletion policy, business identity, and processing errors. They have separate packages and release cycles. Neither engine calls the other directly.

## One station in each system

Evolve Salesforce `WFC27_Packet__c` and WordPress `wfc27_packets` into generic station stores. Every nonempty envelope has an origin, immutable envelope ID, direction (`outbound` or `inbound`), object/type label, source record ID, operation, opaque JSON payload, creation/receipt time, status, and error/claim metadata. The station enforces unique origin + envelope ID. A content envelope is never duplicated merely because a train or acknowledgement repeats.

Station status must distinguish transport receipt from processing completion. A minimal state model is `ready` (local outbound), `received` (remote inbound), `claimed` (processor working), `completed` (processor result available), `acknowledged` (remote has received that result), and `error` (operator review). Claims need a lease so an interrupted engine can resume. Processing errors do not cause WFC27 to resend an already received envelope.

Trip logs remain separate. A train trip exists every minute, even with zero envelopes. No empty staging row is needed just to display a heartbeat.

## The train is bidirectional

Salesforce still initiates the one-minute HTTP exchange. Each request carries up to the configured capacity of *Salesforce outbound* envelopes and acknowledges previously received WordPress envelopes/results. The WordPress response carries receipt acknowledgements and up to its configured capacity of *WordPress outbound* envelopes. Both sides persist newly received envelopes before acknowledging them. Work is processed after receipt, outside the HTTP exchange; a later train carries the result or receipt confirmation.

The transport wrapper is the same in either direction. For example:

```json
{
  "envelope_id": "stable-origin-scoped-id",
  "origin": "salesforce",
  "type": "record.changed",
  "object": "Course__c",
  "record_id": "a00000000000001AAA",
  "operation": "upsert",
  "schema_version": 1,
  "payload": { "any": "JSON owned by the processing engine" }
}
```

WFC27 reads the wrapper to route, count, deduplicate, and trace the envelope. It never interprets `payload`. WordPress-originated envelopes use the same wrapper with `origin: "wordpress"`; they are not limited to processing acknowledgements. Transport can carry a record change, an instruction, a result, or another engine-defined type.

## Adding an object

An administrator adds an object to the appropriate processing engine and defines when changes produce an envelope and how that object's fields are represented. The engine then stages changes locally. The opposite engine needs a corresponding consumer rule to apply or otherwise handle that envelope. No WFC27 code or database schema change is required for a new object. An object can be configured in one direction or both; two-way business updates require explicit field ownership and conflict rules in the engines, not in transport.

This means “anything can be sent and received” at the station level. It does not mean arbitrary JSON should automatically overwrite an arbitrary destination record. An unrecognized envelope remains staged with a visible processing error until a consumer is configured.

## Migration from the current build

1. Retain the working scheduler, security, pause/play, REST route, and trip history. Introduce generic envelope fields and bidirectional station APIs behind the current packet interfaces.
2. Prove an empty train and one opaque envelope in each direction with durable receipt, duplicate suppression, delayed completion, and a failed-consumer case. Do not write a WordPress post in this slice.
3. Move `WFC27_Engine.scan/dispatch` object scanning and JSON assembly into the new Salesforce engine. WFC27 dequeues only prepared envelopes.
4. Move `wfc27_apply_packet` and its post/meta/deletion handlers into the new WordPress engine. Move `WFC27_Sender.processResults` identity reconciliation into the Salesforce engine. The transport stores and carries their outputs without interpreting them.
5. Transfer bindings and identity ownership to the processing engines. Keep compatibility adapters until existing staged packets have drained, then remove WFC27's content-specific paths.

The current post/meta packet remains a processing-engine payload during migration; it is no longer the transport protocol itself.
