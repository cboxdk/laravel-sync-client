---
title: "The push outcomes"
weight: 10
description: "What the client does with each answer, and why."
---

# The push outcomes

`push()` sends the oldest queued mutation and does one of these things. This is
the whole substance of the package.

| The server says | The client does | Why |
|---|---|---|
| a processed result — applied, conflicted, rejected | drops it from the queue, counts it sent | all three are answers. A conflict is not a failure: the competing proposal was preserved |
| `pull_required` (only with a [rebase policy](rebasing.md)) | asks the policy what the edit should now be, and sends the same write again | nothing was stored, so the rethought write is still the one the server is waiting for |
| `unauthenticated` (401) | stops, leaves the queue exactly as it is, and sets `unauthenticated` on the outcome | an expired session is about the device, not the write. Sign in again and sync |
| `retriable` (503) | stops and leaves the queue exactly as it is | retrying is safe **only** under the same identity, because the engine returns the stored result for a repeated one. A fresh id would apply the write twice |
| `mutation_gap` | renumbers from the server's acknowledged point and sends again | the server has not seen something earlier. Nothing is dropped; the queue holds only unacknowledged writes |
| any other error | moves it out of the queue with the reason | it can never be sent again under this identity, so leaving it would block everything behind it forever |

A gap answered twice with the same acknowledged point stops the loop rather than
spinning: if resending has not helped once, it will not help, and looping
against a live server is worse than returning.

## "Noop" is not always "saved"

A resolver that keeps the server's value (`ServerWins`) answers `noop`: nothing
changed, because the other value stayed. `MutationOutcome::applied()` is false
for that write and `overridden()` lists the fields, so it shows up in
`needingAttention()` with everything else the user has to be told about.

## Numbering

A sequence is assigned when a mutation is **sent**, never when it is queued.

Numbering at queue time looks harmless. It is not: a write the transport refuses
has already taken a number the server never receives, so the server waits for
that number forever, every later write comes back as a gap for it, and the
device is wedged with no way out. Numbering at send time makes the hole
impossible.

## Abandoned writes

`$client->outbox()->abandoned()` returns writes that will never land, each with
its reason. **Surface them.** Nothing else in the system will tell the user that
something they typed is gone, and a queue that silently swallows writes is worse
than one that fails loudly.

Then either `requeue($mutationId)` - when the refusal was about the moment, say a
permission the user has since been given - or `dismiss($mutationId)` once the
user has been told. A requeued write goes to the back of the queue under a new
identity, because the server may hold a receipt for the old one.

## When one side restored a backup

A server restored from a backup has forgotten writes this device already had
acknowledged; a device restored from one has forgotten writes the server has.
Either way the server answers `mutation_gap` with where its stream really is,
and the client renumbers from there - downward or upward - and sends again.
Nothing is lost: the queue only ever holds writes the server has not confirmed.

## One push at a time

A device pushes from one place at a time. A queue worker and a scheduler draining
together would send the same write under the same number; the second `push()`
finds the first one's lock - a file next to the local database - and returns at
once with `retryLater`. The operating system drops the lock if the process dies,
so there is no stale lease to wait out.

## Streams

Each entity type and scope a device writes to is its own numbered stream, with
its own replica identity on the wire. The server numbers per replica per space,
and maps types and scopes to spaces by rules the device cannot see; one stream
per (type, scope) means the two sides always count the same thing.
