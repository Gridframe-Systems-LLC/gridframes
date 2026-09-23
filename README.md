# Xlaunch Deploy

A deployment control plane you run for your customers: they sign up, pick a tier, connect or
receive machines, and deploy anything — applications, databases, one-click services, agents —
with automatic HTTPS, a proxy, previews and multi-server orchestration over SSH. You operate it
from a console that sees every team.

Xlaunch Deploy is software of **Northlatch Labs LLC**, licensed to operators under a commercial
agreement. See [`LICENSING.md`](./LICENSING.md).

## Two modes, one code base

| Mode | Who it is for | What it does |
| --- | --- | --- |
| **Selling** (`SELF_HOSTED=false`) | An operator hosting the platform for customers | Public sign-up, tiers sold through Stripe, per-team machine allowances, an operator console over every team, per-team API tokens |
| **Single operator** (`SELF_HOSTED=true`) | One organisation running its own workloads | No accounts beyond your own, no billing, every team unlimited |

The licensed product is the selling mode. The single-operator mode is the same binary with the
storefront switched off, useful for a licensee's own internal workbench.

## What a customer gets

- Sign-up, email verification, a team, invitations
- A choice of tiers on `/subscription/new`; each grants the team an allowance of machines
- Servers connected over SSH (provisioning machines for them is on the roadmap, see `docs/product/OPERATIONS.md`)
- Deployments from Git, Docker images and Compose; one-click services; databases with backups
- Automatic HTTPS for every address, a proxy, preview environments, scheduled tasks
- Notifications, a REST API with team-scoped tokens, and a subscription page to change or cancel

## What the operator gets

- `/admin`: every team, its subscription state, its servers; act on a customer's behalf
- Tiers, prices and allowances configured per instance, never in code
- Stripe as the payment processor (checkout, webhook, customer portal), test or live
- The platform's own database backed up nightly by itself

## Documents

- [`docs/product/INSTALL.md`](./docs/product/INSTALL.md) — a fresh Ubuntu server to a running instance
- [`docs/product/CONFIGURATION.md`](./docs/product/CONFIGURATION.md) — every setting the product reads
- [`docs/product/OPERATIONS.md`](./docs/product/OPERATIONS.md) — running it: backups, mail, the API, hardening, upgrades, roadmap
- [`docs/product/CHANGES.md`](./docs/product/CHANGES.md) — what this product changes against the software it is built on
- [`LICENSING.md`](./LICENSING.md) — the licence this repository is offered under, and the open-source obligations it carries
- [`NOTICE.md`](./NOTICE.md) — attribution
- [`SECURITY.md`](./SECURITY.md) — reporting a vulnerability

## Provenance

Xlaunch Deploy is built on [Coolify](https://github.com/coollabsio/coolify) 4.3.19
(`coollabsio/coolify@59eacc9f`), which is licensed under the Apache License 2.0. The upstream
licence text is kept verbatim in [`LICENSE`](./LICENSE). The modifications and additions that make
it Xlaunch Deploy are Copyright 2026 Northlatch Labs LLC. See `NOTICE.md` and `LICENSING.md`.
