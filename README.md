# Cbox Sync Client

The device side of [Cbox Sync](https://github.com/cboxdk/sync): a durable local
replica, a queue for writes made offline, and the retry semantics that make both
safe.

```sh
composer require cboxdk/laravel-sync-client
```

```php
use Cbox\Sync\Client\Laravel\SyncClient;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Enums\MutationKind;

$client = app(SyncClient::class);

// Works with no network. The write is queued locally and durably.
$client->outbox()->queue($entity, MutationKind::Update, [
    FieldOperation::set('title', $title),
], $baseVersion);

// When there is a connection.
$client->push('tasks', $teamId);
$client->pull('tasks', $teamId);

$client->replica()->record($entity);
```

Everything the device knows lives in one local SQLite file: the replica's
state, the outbox, and which views it has already synced. Kill the process
mid-sync and it comes back knowing what it knew.

## What this package is for

Four things have to be right for every write a device makes, and getting any one
wrong is silent data loss or a queue that never moves again:

- a write that was processed is done, whether it applied, conflicted or was rejected
- a write the server could not take *right now* must be retried under the **same** identity, or it applies twice
- a write the server has not seen yet must be renumbered from where the server actually is
- a write the server will never take must leave the queue, with a reason the application can show

`push()` implements exactly those four. That is the whole reason this package
exists rather than a page of documentation telling you to implement them.

Configuration, the replica id and the local database path are in
[configuration](docs/configuration/reference.md). Requires PHP `^8.4` and
Laravel 12 or 13.

MIT, copyright Cbox. See [LICENSE](LICENSE) and [BUILD-STATUS.md](BUILD-STATUS.md).
