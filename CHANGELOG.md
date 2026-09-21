# Changelog

## 0.3.6
- Show the number of packets waiting for first pickup at each station, separate from the train countdown.

## 0.3.5
- Remove queue and receipt counters from both train widgets. Recent Sync counts explicitly refer to sealed packets, not contents.

## 0.3.4
- WordPress distinguishes packets awaiting a receipt from packets not yet picked up, reoffers unacknowledged packets, and locks their payload after pickup.
- Salesforce shows elapsed overdue time while awaiting a late train.

## 0.3.3
- Show elapsed delay while awaiting a late train instead of freezing the countdown at 00:00.

## 0.3.2
- Keep the four dashboard cells side by side at standard desktop widths.

## 0.3.1
- WordPress dashboard uses four cells for the train, receive endpoint, integration user, and sync filters.
- Standalone station list is named Sync Packets; the duplicate station block was removed from the dashboard.
- Recent Sync rows open a detail view showing the packets sent and received on new trips.

## 0.3.0
- Train envelopes now carry opaque text payloads, including JSON, plain text, and arbitrary text.
- Salesforce Station Items now have an object tab, page layout, and direct record defaults for the train.
- WordPress has independent Station Items list and detail screens with JSON element inspection and pre-departure editing.
- Connection setup sits beside the train countdown and includes the receive endpoint and a link to the receiver's Application Password settings. Trip filters have their own widget, and both train widgets show items waiting to send.

## 0.2.1

Add a WordPress administrator form to stage opaque outbound JSON for train tests.

## 0.2.0

WFC27 now transports opaque JSON between durable stations in both directions. It no longer scans Salesforce business objects, applies WordPress posts/meta, or runs retention and eligibility logic. Existing legacy data is preserved. Trip history and the one-minute train remain.

## 0.1.4

Added recent train trips, including empty packets, with time filters.
