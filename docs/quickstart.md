---
title: "Quickstart"
weight: 2
description: "Queue a write offline, then sync it."
---

# Quickstart

```sh
composer require cboxdk/laravel-sync-client
php artisan vendor:publish --tag=sync-client-config
```

Set the server, this device's replica id, and where its local database lives:

```dotenv
SYNC_CLIENT_URL=https://app.example.com/sync
SYNC_CLIENT_REPLICA=device-7f3a
SYNC_CLIENT_DATABASE=/var/lib/myapp/replica.sqlite
```

The replica id must be **stable for the life of that local database**. Changing
it strands every write still queued under the old one.

```php
$client = app(SyncClient::class);

$client->outbox()->queue(
    new EntityKey($teamId, 'tasks', $taskId),
    MutationKind::Update,
    [FieldOperation::set('title', $title)],
    $baseVersion,
);

$outcome = $client->push('tasks', $teamId);
if ($outcome->retryLater) {
    // The server asked us to come back. The queue is untouched and in order.
}
foreach ($client->outbox()->abandoned() as $dead) {
    // Nothing else will tell the user this write is never going to land.
}

$client->pull('tasks', $teamId);
```

`push()` and `pull()` are ordinary synchronous calls. Put them behind a queued
job, a scheduler entry, or a "sync now" button — the package has no opinion, and
deliberately does not install a scheduler of its own.
