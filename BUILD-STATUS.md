# Build status

Unreleased client for [cboxdk/sync](https://github.com/cboxdk/sync). No tagged release or package publication yet.

Implemented:

- The push loop and its four outcomes, and the pull loop from bootstrap through delta.
- One local SQLite file holding the replica state, the outbox and the view index.
- A swappable transport contract, with an `Illuminate\Http` implementation.

Verification on 2026-09-16:

- Pest: 5 end-to-end tests running the client against a real `cboxdk/laravel-sync` server in the same application, through Laravel's HTTP kernel — real routing, middleware, controllers and database. Covers writing offline and draining the queue, following deltas rather than re-bootstrapping, a refused write leaving the queue without blocking what is behind it, a preserved conflict counting as sent, and a simulated process restart resuming with both synced state and an unsent write intact.
- Pint, PHPStan max with larastan, dependency licenses and a locked audit.

Limits: no scheduler, no background worker, no encryption of the local database, and no credential handling — the host supplies headers and decides when to sync. Conflict candidate values are not delivered by the transport, so a device can see that a conflict exists but not what the other proposals were.
