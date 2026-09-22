# Changelog

## 0.5.0 - Unreleased

Requires `cboxdk/sync` 0.9 and a server on `cboxdk/laravel-sync` 0.7 for pull-before-push.

### Added

- **Decide conflicts on the device.** `'rebase' => KeepMine::class` (or `TakeTheirs`, or your own `RebasePolicy` / `Using` closure) turns on pull-before-push: a stale edit is refused, the policy is asked about each contested field, and the same write goes again knowing what it replaces. After three refusals the server keeps both values; a busy record can delay a write, never lose it.
- `key()` and `record()` queue and read by type and scope, without the application knowing the server's space mapping.
- `references` (`'tasks' => ['project_id' => 'projects']`) and `scoped_by` (`'items' => 'projects'`): a push sends exactly the unsent parent's create first, from wherever it was queued, and rewrites the child's field or scope to the parent's real id before the child goes. A child whose parent was refused is abandoned with it (`parent_abandoned`), and `$client->requeue()` brings writes back under every name given since.
- A write whose receipt the server has pruned (`receipt_pruned`) is abandoned as final at its own position, so older replays behind it keep theirs and are never applied twice.
- A sent write keeps its number, and a stream resends a waiting write before numbering another, so a parent sent out of queue order cannot collide with another write through a lost answer. Writes queued after their parent was named are mapped through the name; an edit to a record whose create was refused is held with it for `requeue()`.
- `outbox()->requeue()`, `dismiss()` and `nameOf()`.
- **A write the server processed and refused is kept**, as abandoned under the server's status (`rejected`, `validation_failed`, `precondition_failed`), until the application dismisses it. It used to live only in the push's return value, and a pull failing after it - or the process ending - lost it without a trace. A refused create now holds back its children too; one that was answered `validation_failed` used to have its child applied pointing at a record that never existed.
- `$client->dismiss()` takes the writes that need a dismissed create with it - `parent_abandoned`, or `parent_unknown` when the create may have landed - and returns how many. `requeue()` refuses a write that may already be on the server - `receipt_pruned`, `protocol_violation`, or one with a sending that got no answer - unless told `evenIfItMayHaveLanded`. A write queued when a device upgraded from 0.4 has no record of its earlier sendings, and counts as sent once. `parent_unknown` writes requeue once `outbox()->found($handle, $name)` has recorded their parent's name.
- **Breaking:** `sync()` no longer throws when the pull fails; it returns the push's outcome with `pullFailure` and `pulled` (whether the view caught up), so the push's report is never thrown away. A 401 on the pull sets `unauthenticated` instead of reporting all clear. `pull()` returns whether it caught up.
- **Headers are read on every request** (`Contracts\SyncHeaders`, config by default), so a token refreshed after sign-in is the one sent.
- A 413 is final whoever answers it. When the queue stops on one write, the outcome names it (`blockedBy`, `httpStatus`, `error`, `retryAfter`).
- A reused handle, or one another scope named differently, is no longer mapped to the wrong record; `page_size` from the config is used; two streams gapping at the same point no longer stop the drain. Handles should be unique on the device (a UUID).
- `$client->outbox()` knows the configured references, so dismissing through it takes a refused parent's children along. A restored stream's settled writes are all counted, and `Retry-After` is read as an HTTP date too (strictly, in GMT).

### Fixed

- **A push sent another scope's queued writes under the scope it was asked for.** It drains only its own scope now.
- **An expired session abandoned every queued write.** A 401 leaves the queue in place and sets `unauthenticated`; so does any error that is not a named refusal - a gateway's or rate limiter's JSON used to drain the queue.
- **A device out of step after either side restored a backup** could never push again, or had every write abandoned. It renumbers to where the server is, down or up.
- **A rebase could win a field the server's resolver kept.** Fields the refusal names as server-kept are dropped from the resent write.
- **Two pushes could overlap** - a queue worker and a scheduler - and send the same write under the same number. One runs at a time per device, on a file lock the OS releases if the process dies.
- **A crash between acknowledging a create and renaming what is queued behind it** stranded those writes. It is one step now.
- **A crash during a bootstrap could wedge the view.** The view is remembered before its page is applied, and the index row is upserted.
- **A server-wins noop was reported as saved.** `MutationOutcome::overridden()` names the fields, and `applied()` is false for them.

### Upgrading

- Each type and scope now travels on its own replica stream. Writes queued before the upgrade keep the stream they were queued on.
- Queue writes under the scope you push with - `$client->key($type, $scope, $id)`. A write queued under another label is no longer sent by that push.

## 0.4.0 - 2026-09-21

### Added

- **`sync()`** — send what this device owes, then take what it is owed, in one call. push() and pull() were the whole surface, so every host wrote the same loop, and the order matters in a way that is not obvious: a device that pulls before pushing reads a server that has not seen its own writes yet, so its edits come back a round trip later and whatever it showed in between is behind its own user.

- **`space()`** — which space a view lives in, once this device has synced. A change notification names a space, because that is the boundary the log is kept in, but a client works in types and scopes and cannot map one to the other: the mapping is the server's own authorization policy. The context saved during bootstrap carries it, so a device knows which channel to listen on.

### Changed

- The quickstart now ends with a device that has written offline, synced, and handled the two things that actually need handling: the rename the server performed, and the writes that were processed but did not land as asked. Those two loops are the difference between a client that works and one that silently loses edits.
- Allows `cboxdk/sync` `^0.8` and tests against `cboxdk/laravel-sync` `^0.6`.

## 0.3.0 - 2026-09-21

### Fixed

- **A dropped network escaped as an uncaught exception.** `HttpTransport` was the only shipped transport that could throw, and nothing in the client caught it, so DNS failing, a refused connection or a timeout became an uncaught `ConnectionException` out of the host's scheduler. The docblock on `push()` says a proxy timeout leaves the queue exactly as it is; that was true of every test double and of no real deployment. It is now answered the way the client already understands a non-protocol response: queue untouched, nothing sent, nothing abandoned, come back later.

  Every double in the suite returns a value, which is exactly why the suite never saw it. The test points the real transport at a closed port rather than faking the failure.

### Changed

- Allows `cboxdk/sync` `^0.7` and tests against `cboxdk/laravel-sync` `^0.5`.

## 0.2.0 - 2026-09-20

### Added (breaking)

- **The client follows the name the server gives a record.** A create goes out under a handle the device made up for itself, because an id a client chooses is attacker-controlled input in a key position. The server answers with the name it actually gave the record, and everything still queued behind that create refers to the handle - left alone, each of those is a write to a record that does not exist.

  `push()` renames the queue and reports what happened in `PushOutcome::$named`. The replica is deliberately untouched: it only ever holds records that came back from the server, so it never knew the handle.

  What the package cannot do is move a field VALUE holding the handle - a child carrying its parent's id - because no library can know which of an application's fields are references. That is why the rename is reported rather than hidden: the application has to move what it stored under the handle, on screen or on disk.

  **Breaking:** `PushOutcome` gains a constructor parameter, and a create's record is no longer stored under the id the caller queued it with.

### Changed

- Requires `cboxdk/sync` `^0.6` for the outbox rename, and tests against `cboxdk/laravel-sync` `^0.4`.

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
