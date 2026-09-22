---
title: "Quickstart"
weight: 2
description: "Zero to a synced record, offline and back."
---

# Quickstart

```bash
composer require cboxdk/laravel-sync-client
```

```dotenv
SYNC_CLIENT_URL=https://your-app.example.com/sync
SYNC_CLIENT_REPLICA=this-device
```

`SYNC_CLIENT_REPLICA` identifies this device and must be stable across restarts.
It is what the server acknowledges against; a device that changes it starts a
new stream and re-sends everything it has not had acknowledged.

## Write, offline

```php
$client = app(SyncClient::class);

$client->outbox()->queue(
    $client->key('tasks', 'team-1', 'my-handle'),
    MutationKind::Create,
    [FieldOperation::set('title', 'Ship it'), FieldOperation::set('status', 'open')],
    baseVersion: 0,
);
```

The key is the type, the scope you will sync it under, and an id. A push for
`team-1` only ever sends writes queued for `team-1`; for a type with no scope,
pass `null`.

Nothing leaves the device. The queue is durable, so this survives being killed.

`'my-handle'` is not the record's id — the server names a new record, and the
id you sent is only what you call it in the meantime.

## Sync

```php
$outcome = app(SyncClient::class)->sync('tasks', 'team-1');
```

One call: send what this device owes, then take what it is owed. It pushes
first on purpose — a device that reads before writing sees a server that has
not seen its own edits yet.

## Handle the two things that need handling

```php
// 1. The server named what you created. Anything you stored under the handle
//    is now under a different id.
foreach ($outcome->named as $rename) {
    $this->relabel($rename->handle->id, $rename->named->id);
}
// The name is also kept, so an app that crashed before relabelling can ask:
// $client->outbox()->nameOf($handle)

// 2. Writes that were processed but did NOT land as asked. Nothing else in the
//    system will mention these, and "sent" is not "saved".
foreach ($outcome->needingAttention() as $problem) {
    $this->tell($problem);
}
```

If you write no other code from this page, write those two loops.

## Read

```php
$record = app(SyncClient::class)->record('tasks', 'team-1', $id);

$record?->value('title')->value();
$record?->version->value; // send this as baseVersion when you edit it
```

## Stay current without polling hard

Subscribe to the server's change notification, and call `sync()` when it fires.
The client can tell you which channel to listen on once it has synced at least
once:

```php
$space = app(SyncClient::class)->space('tasks', 'team-1');
```

Keep a slow poll as well — every few minutes is enough. Notification delivery is
at-most-once, so a missed signal must never mean missed data. The signal makes
sync prompt; the cursor is what makes it correct.

## What happens when things go wrong

You do not have to handle any of this. It is handled:

- **The network drops.** The queue is untouched and `retryLater` is true.
- **The session expires.** The queue is untouched, `unauthenticated` is true.
  Sign the user in again and sync; nothing was dropped.
- **A response is lost.** Re-sending carries the same mutation id, so the server
  answers from its receipt rather than applying twice.
- **The server says reset.** The view is rebuilt from a fresh bootstrap
  automatically, once. A second reset in a row is raised to you.
- **Two people edit the same field.** Both proposals survive; the outcome tells
  you so rather than picking silently. If you would rather the device decide -
  latest edit wins, first edit wins, or your own merge - set one line of config:
  `'rebase' => KeepMine::class`. See
  [Deciding conflicts on the device](core-concepts/rebasing.md).
