# Changelog

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
