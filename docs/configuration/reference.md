---
title: "Reference"
weight: 10
description: "Every key in config/sync-client.php."
---

# Configuration reference

| Key | Env | Default | Meaning |
|---|---|---|---|
| `url` | `SYNC_CLIENT_URL` | — | The server's sync prefix |
| `headers` | — | `[]` | Whatever the server's middleware expects |
| `timeout` | `SYNC_CLIENT_TIMEOUT` | `30` | Seconds per request |
| `replica` | `SYNC_CLIENT_REPLICA` | — | This device's stream id. Stable for the life of the local database |
| `database` | `SYNC_CLIENT_DATABASE` | `storage/sync/replica.sqlite` | Local SQLite file |
| `page_size` | `SYNC_CLIENT_PAGE_SIZE` | `100` | Records per bootstrap page |

There is no retry or backoff setting. Whether to retry depends on which of the
four answers the server gave, not on a timer, so it is a decision the client
makes rather than a number you configure.
