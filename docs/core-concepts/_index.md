---
title: "Core Concepts"
weight: 10
description: "What the client does with each answer from the server."
---

# Core Concepts

- [The push outcomes](push-outcomes.md) — what `push()` does with each
  answer, and which outcomes your application has to surface.
- [Deciding conflicts on the device](rebasing.md) — pull before push: refuse a
  stale edit, rethink it with a policy, send it again.
- [When the server orders a reset](resets.md) — why a view gets rebuilt, what
  the client handles on its own, and the one case it hands back to you.
