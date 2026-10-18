# GridFrames

Agent-native deployment platform: customers connect a machine, deploy from Git or a Docker
image, and get HTTPS, databases, one-click services, previews and a REST API — with an agent
surface that can drive all of it. You operate the platform from a console that sees every team.

GridFrames is software of **GridFrame Systems LLC**, licensed to operators under a commercial
agreement. See [`LICENSING.md`](./LICENSING.md).

## Status

In active development. The deployment core (machines, deployments, databases, HTTPS, proxy,
API) is running in production; provisioning of machines from pools and the agent front door
are the next major pieces. See the roadmap in
[`docs/product/OPERATIONS.md`](./docs/product/OPERATIONS.md).

## Two modes, one code base

| Mode | Who it is for | What it does |
| --- | --- | --- |
| **Selling** (`SELF_HOSTED=false`) | An operator hosting the platform for customers | Public sign-up, tiers sold through Stripe, per-team machine allowances, an operator console over every team, per-team API tokens |
| **Single operator** (`SELF_HOSTED=true`) | One organisation running its own workloads | No accounts beyond your own, no billing, every team unlimited |

The licensed product is the selling mode. The single-operator mode is the same binary with the
storefront switched off, useful for a licensee's own internal workbench.

## What a customer gets

- Sign-up, email verification, a team, invitations
- A choice of tiers; each grants the team an allowance of machines
- Servers connected over SSH (provisioning machines for them is on the roadmap, see `docs/product/OPERATIONS.md`)
- Deployments from Git, Docker images and Compose; one-click services; databases with backups
- Automatic HTTPS for every address, a proxy, preview environments, scheduled tasks
- Notifications, a REST API with team-scoped tokens, and a subscription page to change or cancel

## What the operator gets

- `/admin`: every team, its subscription state, its servers; act on a customer's behalf
- Tiers, prices and allowances configured per instance, never in code
- Stripe as the payment processor (checkout, webhook, customer portal), test or live
- The platform's own database backed up nightly by itself

## Getting started (operators)

1. [`docs/product/INSTALL.md`](./docs/product/INSTALL.md) — a fresh Ubuntu server to a running instance
2. [`docs/product/CONFIGURATION.md`](./docs/product/CONFIGURATION.md) — every setting the product reads
3. [`docs/product/OPERATIONS.md`](./docs/product/OPERATIONS.md) — running it: backups, mail, the API, hardening, upgrades, roadmap

## Security

Vulnerability reports: [`SECURITY.md`](./SECURITY.md). Please do not open public issues for
security problems. Every release image is built by CI from a tagged commit
(`.github/workflows/deploy-ovh.yml`); base images are pinned by digest.

## Contributing

PRs welcome. CI enforces a conventional PR title, a description, and the repository's
contributor agreement (`.github/PULL_REQUEST_TEMPLATE.md`). Keep AI-generated content out of
commit messages and PR descriptions — contributions should read as written by their author.
Commit small, test what you ship, and match the surrounding code style.

## Licence & provenance

- [`LICENSING.md`](./LICENSING.md) — the licence this repository is offered under, and the open-source obligations it carries
- [`NOTICE.md`](./NOTICE.md) — attribution
- Built on [Coolify](https://github.com/coollabsio/coolify) 4.3.19 (`coollabsio/coolify@59eacc9f`),
  licensed under the Apache License 2.0; upstream licence text kept verbatim in [`LICENSE`](./LICENSE).
  The modifications and additions that make it GridFrames are Copyright 2026 GridFrame Systems LLC.
