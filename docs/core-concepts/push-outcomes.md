---
title: "The four push outcomes"
weight: 10
description: "What the client does with each answer, and why."
---

# The four push outcomes

`push()` sends the oldest queued mutation and does one of four things. This is
the whole substance of the package.

| The server says | The client does | Why |
|---|---|---|
| a processed result — applied, conflicted, rejected | drops it from the queue, counts it sent | all three are answers. A conflict is not a failure: the competing proposal was preserved |
| `retriable` (503) | stops and leaves the queue exactly as it is | retrying is safe **only** under the same identity, because the engine returns the stored result for a repeated one. A fresh id would apply the write twice |
| `mutation_gap` | renumbers from the server's acknowledged point and sends again | the server has not seen something earlier. Nothing is dropped; the queue holds only unacknowledged writes |
| any other error | moves it out of the queue with the reason | it can never be sent again under this identity, so leaving it would block everything behind it forever |

A gap answered twice with the same acknowledged point stops the loop rather than
spinning: if resending has not helped once, it will not help, and looping
against a live server is worse than returning.

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
