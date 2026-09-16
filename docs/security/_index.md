---
title: "Security"
weight: 40
description: "What lives on the device, and what it means if the device is lost."
---

# Security

The local database holds everything this device has synced, in the clear. It is
a copy of whatever the server's view let it read. If the device is lost, treat
that data as disclosed, and revoke the device's credentials so it cannot sync
again.

This package does not encrypt the local file. On a desktop or mobile platform
that is the operating system's job, and doing it badly here would be worse than
not doing it — see the engine's own honest-crypto stance.

Authentication is entirely the host's: whatever `sync-client.headers` carries is
what the server sees. The package never invents a credential, never refreshes
one, and never stores one.
