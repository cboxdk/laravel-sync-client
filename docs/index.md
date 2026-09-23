---
title: "Cbox Sync Client"
weight: 1
description: "A durable local replica and an outbox for one device."
---

# Cbox Sync Client

This package is one device's side of the protocol. The server half is
`cboxdk/laravel-sync`; the engine and the local storage are in `cboxdk/sync`.

What it adds is the loop: drain the queue, then catch up, and do the right thing
with each answer the server gives.

## Sections

- [Quickstart](quickstart.md) — write offline, sync, and the two loops worth writing
- [Requirements](requirements.md)
- [Getting started](getting-started/_index.md)
- [Core concepts](core-concepts/_index.md) — the push outcomes, and deciding conflicts on the device
- [Configuration](configuration/_index.md)
- [Security](security/_index.md)
