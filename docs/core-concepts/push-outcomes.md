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
| a processed result — applied, noop, conflicted | drops it from the queue, counts it sent | these are answers. A conflict is not a failure: the competing proposal was preserved |
| a processed refusal — `rejected`, `validation_failed`, `precondition_failed` | counts it sent, and keeps it as abandoned under that status | the write will never land. Kept rather than dropped, because the outcome returned by this push is gone once the process is - and a refused create goes on holding back the writes that depend on it |
| `pull_required` (only with a [rebase policy](rebasing.md)) | asks the policy what the edit should now be, and sends the same write again | nothing was stored, so the rethought write is still the one the server is waiting for |
| `unauthenticated` (401) | stops, leaves the queue exactly as it is, and sets `unauthenticated` on the outcome | an expired session is about the device, not the write. Sign in again and sync |
| `retriable` (503) | stops and leaves the queue exactly as it is | retrying is safe **only** under the same identity, because the engine returns the stored result for a repeated one. A fresh id would apply the write twice |
| `mutation_gap` | renumbers from the server's acknowledged point and sends again | the server has not seen something earlier. Nothing is dropped; the queue holds only unacknowledged writes |
| `receipt_pruned` | abandons it as `receipt_pruned`; when the server is ahead of the device (a restore), everything still queued on that stream too | it may already have been applied and its answer is gone: sending it again under any number could apply it twice |
| a refusal of this write - `invalid_request`, `invalid_field_value`, `forbidden`, `field_not_writable`, `unknown_type`, `protocol_violation`, and a 413 from anything | abandons it with the reason | it can never be sent again under this identity, so leaving it would block everything behind it forever |
| anything else - a 5xx, a 429, an HTML page, no answer | stops, leaves the queue exactly as it is, and says which write it stopped on (`blockedBy`, `httpStatus`, `error`, `retryAfter`) | not the server refusing the write. A queue held up by one write can be told apart from a device that is offline |

A child whose parent create was abandoned or refused is abandoned with it as
`parent_abandoned`, rather than sent pointing at a record that will never exist.

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

Then either `$client->requeue($mutationId)` - when the refusal was about the
moment, say a permission the user has since been given - or
`$client->dismiss($mutationId)` once the user has been told. A requeued write goes
to the back of the queue under a new identity, because the server may hold a
receipt for the old one. Dismissing a create abandons the writes that need its
record as `parent_abandoned`, for you to report in turn.

A `receipt_pruned` or `protocol_violation` write may already be on the server.
`requeue()` refuses it unless you pass `evenIfItMayHaveLanded: true` after
checking, because a second identity applies it twice - a create becomes two
records.

`sync()` returns the push's outcome even when the pull after it fails; the
failure is on `pullFailure`, and an expired session sets `unauthenticated` for
either half.

## When one side restored a backup

A server restored from a backup has forgotten writes this device already had
acknowledged; a device restored from one has forgotten writes the server has.
Either way the server answers `mutation_gap` with where its stream really is,
and the client renumbers from there - downward or upward - and sends again.

With one exception: when the server has pruned the answers for positions the
restored device is about to reuse, it cannot tell a replay from a new write and
answers `receipt_pruned`. Every write the device still has queued on that stream
may be one it had sent before the backup was restored, so all of them are
abandoned as `receipt_pruned` together and counted in `abandoned`; new writes go
out after the server's position. Check each against the server before requeueing
it with `evenIfItMayHaveLanded: true`. A fresh install should use a fresh
`replica` id, or the same applies to anything it queues before its first push.

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
