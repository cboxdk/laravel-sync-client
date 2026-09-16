---
title: "Installation"
weight: 10
description: "Install, configure the device, and choose where its data lives."
---

# Installation

```sh
composer require cboxdk/laravel-sync-client
php artisan vendor:publish --tag=sync-client-config
```

The service provider is discovered automatically. Nothing runs on its own: this
package registers services and never schedules work.

## The local database

`sync-client.database` is a SQLite file holding the replica's state, the outbox
and the view index. It must be durable and writable, and it must not be shared
between devices.

It has to survive restarts for a reason that is not obvious: a device that
forgets what it queued cannot produce a mutation stream the server will accept
without being told where to resume, and a device that forgets its delete
watermarks can have records it already saw deleted come back.

## The replica id

`sync-client.replica` identifies this device's stream. Generate it once, store
it with the local database, and never change it while that database exists.

The transport namespaces it under the authenticated user on the server side, so
two users on the same machine do not collide — but two *devices* sharing one id
will fight over the same sequence numbers.
