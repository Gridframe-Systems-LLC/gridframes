# Xlaunch v5 Architecture

This directory is the canonical architecture reference for Xlaunch v5 from the
Laravel control-plane point of view.

v5 has three major building blocks:

```text
User / API / Git webhook
        ↓
Xlaunch Laravel control plane
        ↓ HTTP over /run/xlaunch/flux.sock
Flux
        ↓ outbound gRPC Agent.Stream
coold on each host
        ↓
Podman / networks / firewall / DNS / Corrosion / builder
```

## Documents

| Document | Purpose |
| --- | --- |
| [Overview](overview.md) | High-level mental model and data flow. |
| [Responsibility split](responsibility-split.md) | What belongs in Xlaunch, Flux, and coold. |
| [Primitives](primitives.md) | Canonical host primitive surface Xlaunch may dispatch. |
| [Deploy flows](deploy-flows.md) | User-functionality flows, starting with `nginx:alpine`. |
| [ADRs](adr/README.md) | Architecture decision records for Xlaunch, Flux, coold, and their contracts. |
| [ADR 0001](adr/0001-control-plane-flux-coold-split.md) | Decision record for the v5 split. |

## Core rule

Xlaunch owns user intent and application state. Flux routes requests. coold
executes explicit host primitives. coold must not grow app, team, RBAC,
deployment, billing, or audit concepts.
