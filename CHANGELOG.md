# Changelog

## 0.1.1 - 2026-09-20

### Fixed

- **A reset bricked the device instead of rebuilding it.** `SyncRequestFailed::requiresReset()`, `MultiViewClient::resetView()` and `ViewIndex::forget()` all existed and none of them was ever called: every non-2xx threw straight out of `pull()`, including the 409 that means "your local state for this view is no longer valid". So retention running past a slow device's cursor, an epoch rotation, or a changed view filter each made `pull()` throw on every sync from then on, with no recovery short of deleting the replica database by hand - and epoch rotation is the documented lever for forcing a rebuild.

  `pull()` now catches a reset, drops the view's memberships, cursor and index entry, and rebuilds from a fresh bootstrap. The replica is reset before the index entry is dropped: the other order loses the fingerprint that finds the context, and the memberships it names are then unreachable. The rebuild is attempted once - a second reset means the view changed again mid-rebuild, and retrying in a loop would spin against a moving target rather than letting the application back off.

### Added

- `docs/core-concepts/resets.md` - what causes a reset, what the client handles on its own, and the one case it hands back.

### Changed

- Allows `cboxdk/sync` `^0.5`. The client uses nothing added in 0.5, so both lines resolve; widened rather than moved, because pinning it forward would force the engine version on an application that has not moved its server side yet.

## 0.1.0 - 2026-09-18

### Initial release

- `SyncClient` drains the outbox and brings views up to date, implementing the four push outcomes: a processed result is done, a retriable one leaves the queue untouched for a retry under the same identity, a gap renumbers from where the server actually is, and anything else leaves the queue with its reason.
- **Only a recognisable protocol answer moves the queue.** A gateway's HTML 502 was read as a considered refusal and the write abandoned; a captive portal's 200 was read as an acknowledgement. A transient outage could drain the entire queue. Anything that is not one of ours now leaves the queue exactly as it is.
- **A push sends only the entity type it names.** The outbox holds every write this device has made across every type, and the wire payload carries no type of its own, so draining the whole queue submitted other types' writes as the type being pushed.
- An interrupted bootstrap resumes with the token the replica is waiting for. Opening a fresh one produces a page the replica correctly refuses as out of order, with no way back.
- `PushOutcome::needingAttention()` returns what the server decided about each write. "Sent" is not "saved": a rejection or a conflict is a final answer the user is entitled to see, and nothing else in the system will mention it.
- Offline write chains are transmitted, so two edits to the same field while offline no longer conflict with each other.
- Request bodies and responses are encoded and decoded to objects with `JSON_PRESERVE_ZERO_FRACTION`. A field value is canonical JSON text compared exactly, so `{}` decoded as `[]` or a float `1.0` sent as `1` stores something other than what the device holds.
- A device's replica state, its outbox and its view index share one local SQLite file, so a process killed mid-sync comes back knowing what it knew.
