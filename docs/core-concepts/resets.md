---
title: "When the server orders a reset"
weight: 20
description: "What a 409 means, what the client does about it, and the one case it hands back to you."
---

# When the server orders a reset

A reset is the server saying that what this device knows about a view can no
longer be trusted. It is not an error in the request, and retrying the same
request cannot help: the cursor the device is holding no longer means anything.

`pull()` handles it. You do not catch anything.

```php
$client->pull('tasks', $teamId);
```

## What causes one

| Reason | What happened |
|---|---|
| `history_pruned` | The device was offline long enough that retention removed the commits its cursor asks for. |
| `context_changed` | The view's filter, the schema version or the epoch changed, so the cursor belongs to a view that no longer exists. |
| `cursor_ahead` | The cursor points past the server's own log — a restore from backup, or a rebuilt space. |
| `bootstrap_token_unknown` | A continuation token the server will not honour. |
| `bootstrap_session_expired` | A legitimate token whose session aged out. |

All of them arrive as HTTP 409 with `error: reset_required` and the reason in
`reason`.

## What the client does

1. Drops every membership this device recorded for that view, and forgets the
   view's cursor and context.
2. Forgets the local note of which view fingerprint belongs to this
   `(type, scope)`.
3. Opens a fresh bootstrap and follows it through to delta.

Canonical knowledge — the version and tombstone of each record — deliberately
survives. It is what stops a later page from resurrecting something this device
already saw deleted.

## The one case you get back

The rebuild is attempted **once**. If the server orders a second reset while the
device is rebuilding, `SyncRequestFailed` is thrown. That means the view is
changing faster than a device can follow it, and looping would spin against a
moving target. Back off and try the next sync cycle.

```php
try {
    $client->pull('tasks', $teamId);
} catch (SyncRequestFailed $failed) {
    if ($failed->requiresReset()) {
        // The view changed again mid-rebuild. Not worth retrying now.
        return;
    }

    throw $failed;
}
```

Every other refusal — a rejected request, an unknown type — is thrown from
`pull()` as it always was (`sync()` puts it on `pullFailure` instead). A busy
server is not a refusal: `pull()` returns false and the next one carries on. Recovery is for a reset and nothing else, because
silently rebuilding on any failure would turn one bad request into a full
re-download on every sync.

## Queued writes are untouched

A reset concerns what this device has *read*. Anything sitting in the outbox is
still queued, still numbered, and still sent by the next `push()`. Rebuilding a
view never discards a write the user made.
