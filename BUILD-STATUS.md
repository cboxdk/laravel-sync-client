# Build status

Client for [cboxdk/sync](https://github.com/cboxdk/sync). 0.5.0 is prepared and not yet tagged; it requires cboxdk/sync 0.9 and, for pull-before-push, a cboxdk/laravel-sync 0.7 server.

Implemented:

- The push loop and every outcome it can get, parent-first sending of records created offline, and the pull loop from bootstrap through delta.
- One local SQLite file holding the replica state, the outbox and the view index; one push at a time per device.
- Refused writes kept until the application dismisses them, and a guard against sending again anything that may already be on the server.
- A swappable transport contract, with an `Illuminate\Http` implementation that reads its headers on every request.

Verification: the suite runs the client against a real `cboxdk/laravel-sync` server in the same application, through Laravel's HTTP kernel - real routing, middleware, controllers and database - plus scripted transports for what a server alone cannot produce (gateways, timeouts, lost answers). Pint, PHPStan max with larastan and the strict rules, dependency licenses and an audit run on every build; see `composer qa`.

Limits: no scheduler, no background worker, no encryption of the local database, and no credential handling - the host supplies headers and decides when to sync.
