# Station-first architecture proposal

Status: design proposal; no runtime change or deployment.

## Ownership boundary

WFC27 owns the timetable, authenticated transport, durable stations, receipt acknowledgement, observability, and pause/play. It does not decide which Salesforce records qualify, bind fields, construct WordPress post/meta data, create WordPress posts, or apply identity results to business records.

A Salesforce preparation engine owns eligibility, object and field bindings, change discovery, and JSON construction. A WordPress application engine owns validating the content contract, post/meta upserts, deletion, locally owned field protection, and result construction. Identity reconciliation belongs to processing, but WFC27 carries its result envelopes without interpreting them.

This is a boundary between products, not a return to synchronous processing. The minute train and delayed result exchange remain unchanged.

## Stations

Each side has one durable station entity for *envelopes*, with a `direction` of outbound or inbound. A station can therefore accept a train before a processor is installed or healthy. It stores the opaque JSON body, a stable envelope ID, created/received time, status, attempts or error for operator review, and an idempotency key. It does not mirror the WordPress posts table.

Salesforce can evolve `WFC27_Packet__c` into this entity. WordPress can evolve `wfc27_packets`. Keep the existing trip logs separate: a trip records every departure or arrival, including an empty heartbeat; a station record exists only when a nonempty envelope is queued. This avoids writing one staging record every minute on both sides.

Proposed station states: `ready` (outbound), `received` (inbound), `claimed`, `completed`, `reported`, and `error`. A received envelope is committed before the API acknowledges receipt. A claimed envelope uses a lease or stale-claim recovery so a crashed processor does not strand it. The station enforces uniqueness on origin + envelope ID; transport never creates duplicate processing work from a repeated delivery.

## Flow

1. The Salesforce preparation engine writes an outbound JSON envelope into the Salesforce station. It may include post/meta data, deletes, and processing instructions. WFC27 treats the body as opaque.
2. Each scheduled departure takes up to the configured batch capacity of ready envelopes, or sends an empty heartbeat. The receiver writes incoming envelopes into its station, then returns receipt acknowledgement plus previously completed result envelopes.
3. The WordPress application engine claims inbound envelopes and writes posts/meta. It writes a result envelope into the WordPress station. A later train carries that result home.
4. Salesforce WFC27 writes the result to its inbound station and acknowledges receipt. A Salesforce reconciliation engine claims it and updates identity or error records. A later train acknowledges completed reporting so WordPress can retire its result envelope.

The packet summary and content contract can remain recognizable, but transport-only fields (envelope ID, direction, schema version, correlation ID, receipts) should be separated from processing payload. Content engines version and validate their own payload schema. A processor error is a result envelope, not an automatic transport resend.

## Migration from the current build

- Retain the minute scheduler, pause/play, security, train logs, and WP REST route in WFC27.
- Retain `WFC27_Packet__c` and `wfc27_packets`; extend them for two directions and durable claims rather than replacing them immediately.
- Move `WFC27_Engine.scan/dispatch` content selection and JSON assembly into the Salesforce preparation engine. WFC27 should only dequeue prebuilt envelopes.
- Move `wfc27_apply_packet` and its post/meta/deletion handlers into a WordPress application engine. WFC27's inbox worker should only claim, hand off, and persist the returned result.
- Move `WFC27_Sender.processResults` into Salesforce reconciliation. WFC27 should only stage the inbound result.
- Preserve the current identity map and binding data during migration, with ownership transferred to the appropriate engines. Migrate one side at a time behind an adapter so the live train remains observable.

## First implementation slice

Prove an empty train and one opaque test envelope through both stations without touching a WordPress post. Verify durable receipt, delayed result, duplicate delivery, pause/play, trip history, and the hour/day filters. Only then wire the Salesforce producer and WordPress consumer to the station contract.

Open product decision: whether the two processing engines are new AlphaSys components or existing engines with adapters. That choice affects packaging and ownership, not the station contract.
