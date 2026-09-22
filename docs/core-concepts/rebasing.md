---
title: "Deciding conflicts on the device"
weight: 30
description: "Pull before push: refuse a stale edit, rethink it with a policy, send it again."
---

# Deciding conflicts on the device

Two people edit the same field while one of them is offline. Someone has to
decide which value wins.

**By default the server decides**, and the default server keeps both values in a
conflict group until a person picks one. Nothing is lost, but your app needs a
screen for picking.

**With a rebase policy the device decides.** One line of config:

```php
// config/sync-client.php, next to the keys already there
return [
    'rebase' => \Cbox\Sync\Client\Laravel\Rebase\KeepMine::class,
];
```

or at runtime:

```php
use Cbox\Sync\Client\Laravel\Rebase\KeepMine;

$client->rebaseWith(new KeepMine);
```

## What happens

1. The device sends its edit and says it wants to decide conflicts itself.
2. If someone else changed the same field first, the server refuses and stores
   nothing. The refusal names each such field and its current value.
3. The client asks your policy about each of those fields - and only those. A
   field only this device changed, or that both set to the same value, never
   reaches the policy.
4. The rethought edit replaces the queued one and is sent again under the same
   identity. It is now a write made *knowing* what it replaces.
5. `sync()` pulls afterwards, as it always does, so the device shows the result.

If the record keeps changing under it, the client stops after
`SyncClient::REBASE_ATTEMPTS` (3) tries and lets the server keep both values. A
busy record can delay a write; it can never make one disappear.

## Shipped policies

| Policy | The contested field ends up | Good for |
|---|---|---|
| `KeepMine` | this device's value | titles, statuses, dates - the latest deliberate edit is the truth |
| `TakeTheirs` | the value that reached the server first | fields where an edit made offline should not overrule one already seen |
| `Using` | whatever your closure says | anything else, per field |

The device's edits to fields nobody else touched land in every case.

## Writing your own

```php
use Cbox\Sync\Client\Laravel\Rebase\Using;
use Cbox\Sync\Client\Laravel\ValueObjects\RebaseChoice;
use Cbox\Sync\Client\Laravel\ValueObjects\StaleField;

$client->rebaseWith(new Using(fn (StaleField $field): RebaseChoice => match ($field->field) {
    'notes' => RebaseChoice::use($field->theirs?->value()."\n".$field->mine->value()),
    'status' => RebaseChoice::takeTheirs(),
    default => RebaseChoice::keepMine($field),
}));
```

A class implementing `Contracts\RebasePolicy` works the same way and can be
named in config.

`$field->theirs` is null when this device may write the field but not read it:
the server never discloses a value past the read whitelist, so the policy has to
decide without seeing it.

## What the server still decides

A policy only ever gets the cases the server would otherwise have kept both
values for. A field the resolver settled for the server is dropped from the
resent write - the refusal says which - so rebasing can never win a field the
host said the device must lose. If the host's resolver settles a field as client-wins or
server-wins, or rejects conflicting writes outright, that still happens
exactly as before. The device cannot use this to get around the server's rules.
