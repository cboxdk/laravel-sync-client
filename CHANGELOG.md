# Changelog

## Unreleased

### Initial release

- `SyncClient` drains the outbox and brings views up to date, implementing the four push outcomes: a processed result is done, a retriable one leaves the queue untouched for a retry under the same identity, a gap renumbers from where the server actually is, and anything else leaves the queue with its reason. Getting any of those wrong is silent data loss or a permanently wedged queue, which is why they live here rather than in each application.
- A device's replica state, its outbox and its view index all share one local SQLite file, so a process killed mid-sync comes back knowing what it knew.
- `ViewIndex` remembers which context fingerprint belongs to which (type, scope). The fingerprint is computable only on the server, so without it a device reopens a bootstrap on every sync and is told it already entered delta.
- `HttpTransport` speaks the `cboxdk/laravel-sync` envelope over `Illuminate\Http`. It never retries on its own: whether a retry is safe depends on the answer, which is the client's decision.
