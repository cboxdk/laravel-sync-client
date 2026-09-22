---
title: "Reference"
weight: 10
description: "Every key in config/sync-client.php."
---

# Configuration reference

| Key | Env | Default | Meaning |
|---|---|---|---|
| `url` | `SYNC_CLIENT_URL` | — | The server's sync prefix, as its final https URL: a redirect is never followed - it would carry the device's credentials along - and is reported as `redirected` |
| `headers` | — | `[]` | Whatever the server's middleware expects. Read on every request, so `config()->set('sync-client.headers.Authorization', ...)` after the user signs in again takes effect at once; bind `Contracts\SyncHeaders` to read a token from anywhere else |
| `timeout` | `SYNC_CLIENT_TIMEOUT` | `30` | Seconds per request |
| `replica` | `SYNC_CLIENT_REPLICA` | — | This device's stream id. Stable for the life of the local database |
| `database` | `SYNC_CLIENT_DATABASE` | `storage/sync/replica.sqlite` | Local SQLite file |
| `page_size` | `SYNC_CLIENT_PAGE_SIZE` | `100` | Records per bootstrap page |
| `references` | — | `[]` | Per entity type, `field => the type it points at`. A push sends an unsent parent first and rewrites the field to its real id |
| `scoped_by` | — | `[]` | `type => the type whose id is its scope`. Writes queued under a parent's handle move to its name, and the parent is sent first |
| `rebase` | — | `null` | A `RebasePolicy` class. Null lets the server decide conflicts; a policy makes this device decide them. See [Deciding conflicts on the device](../core-concepts/rebasing.md) |

There is no retry or backoff setting. Whether to retry depends on which
answer the server gave, not on a timer, so it is a decision the client
makes rather than a number you configure.
