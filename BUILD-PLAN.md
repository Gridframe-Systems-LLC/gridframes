# Xlaunch — Agent Budgets

**Read this first. It is the only status document. Rewrite it; never append.**

---

## What we are building

The one thing Xlaunch will do that no competitor does:

> **An agent is a deployable unit with a budget.**

You deploy an app or an agent in Xlaunch. It gets an inference route through the
Xlaunch Gateway, a token cap, and a meter. When it hits the cap it stops. The
dashboard shows what every deployment cost, by name, this period.

Verified on 2026-09-14: of the seven agent-hosting platforms in the market
(Northflank, E2B, Modal, Fly.io Sprites, Runloop, Vercel Sandbox, Cloudflare
Sandbox) none are self-hostable, and none offer per-agent cost metering or
budgets. The self-hosted PaaS field (Coolify, Dokploy, CapRover) has no AI
features at all. That gap is the product.

**Scope discipline.** This is the thin slice, not a platform. No GPU
scheduling, no model hosting, no marketplace. Those come after something is
paying.

---

## The two systems

| | Xlaunch (control plane) | Xlaunch Gateway (enforcement) |
|---|---|---|
| Where | `~/Desktop/xlaunch` | `~/WORK.CLAUDE/northlatch-gateway/gateway` |
| Stack | Laravel 12.65, PHP 8.4, Livewire, Postgres 15, Redis 7, Traefik | Node + TypeScript, Express, better-sqlite3 |
| Role | Sets caps, shows spend, injects keys at deploy | Issues keys, counts tokens, refuses over cap |
| Port | 8000 (dev) | 3001 |

**Xlaunch never sees an inference request.** It is the control surface only.
The gateway is the sole enforcement point because it is the only thing in the
request path. Keep that separation — it is what makes the feature honest.

---

## What already exists (verified, do not rebuild)

The gateway already has the whole enforcement half. This was the finding that
shrank the project:

- `client_profiles` table carries `token_cap INTEGER` (NULL = unlimited) and
  `cap_period TEXT` — `'day'` / `'month'` / `'total'`.
  Migration: `src/db/migrations/20260908_000001_client_profile_spend_caps.ts`
- `client_profile_usage` — durable per-period counter, deliberately separate
  from the prunable `requests` table so a cap cannot silently reset.
- Admin API, mounted `app.use('/api/client-profiles', requireAuth, ...)`:
  - `GET /api/client-profiles` — list
  - `POST /api/client-profiles` — create (returns the key)
  - `PATCH /api/client-profiles/:id` — set cap / period
  - `POST /api/client-profiles/:id/rotate` — rotate key
  - `POST /api/client-profiles/:id/reset-usage`
  - `DELETE /api/client-profiles/:id`
- Customer accounts and billing exist:
  `20260908_000002_customer_accounts_and_billing.ts`, plus `/api/billing/*`.

**A client profile is already a key with a cap and a meter.** That is the
per-deployment inference route. Xlaunch's job is to create one per deployment
and show the number.

---

## Steps

Mark each `[ ]` → `[x]` as it lands. Put the date and nothing else.

### Phase 0 — ground

- [ ] **0.1** DigitalOcean droplet, Frankfurt, Basic 8 GB / 4 vCPU ($48/mo).
      CPU/RAM resize is reversible; disk growth is not. Do not grow the disk.
- [ ] **0.2** Install Xlaunch on it. Images are **not published and will not
      be** — no GitHub, no registry. Build locally, ship over SSH:
      `docker build -f docker/production/Dockerfile -t northlatch/xlaunch:latest .`
      `docker save northlatch/xlaunch:latest | gzip | ssh root@HOST 'gunzip | docker load'`
      Then `pull_policy: never` on those services in `docker-compose.prod.yml`,
      which currently hardcodes `${REGISTRY_URL:-docker.io}/northlatch/xlaunch`.
- [ ] **0.3** Migrate the gateway. Its entire state is one SQLite file on
      `gateway-first` (104.248.38.247). **The original `ENCRYPTION_KEY` must
      move with it in the same step** — the key on the Coolify instance was
      generated fresh, and loading the database under it makes every stored
      provider key unreadable, silently.
- [ ] **0.4** Consolidate the five droplets (heron, wren, gateway, services,
      prover) onto the new box. Then purge.

### Phase 1 — the feature

- [ ] **1.0** *Blocker, do first.* The gateway's `requireAuth`
      (`src/middleware/requireAuth.ts`) accepts a Bearer or `x-dashboard-token`
      validated by `validateSession()` — a **session** token, not a machine
      credential. Xlaunch needs a long-lived one. Add a service-token concept
      to the gateway, or have Xlaunch hold and refresh a session. Decide and
      record the decision here.
- [ ] **1.1** Xlaunch migration: `inference_routes` —
      `application_id`, `profile_id` (gateway), `token_cap`, `cap_period`,
      `last_usage`, `last_synced_at`. One route per application.
- [ ] **1.2** Xlaunch service class `App\Services\GatewayClient` — thin wrapper
      over the six endpoints above. No business logic in it.
- [ ] **1.3** Livewire component on the application detail page: attach a
      route, set a cap and period, show usage against cap, rotate, detach.
- [ ] **1.4** Inject the profile key as an env var at deploy time, alongside the
      existing env var mechanism. The app calls the gateway, never a provider.
- [ ] **1.5** Scheduled job to pull usage per route and store `last_usage`.
      One call per route, not per request.
- [ ] **1.6** Pest tests: cap set, cap enforced (gateway-side), usage displayed,
      key rotation invalidates the old key, detach cleans up the profile.

### Phase 2 — proof

- [ ] **2.1** Move Heron and Wren onto it. They become the first two budgeted
      agents, which is also the demo.
- [ ] **2.2** Take the real number — cost per agent per month — and put it on
      `projectxprotocol.dev`. Not a claim, a measurement, the same way labs'
      85% spend cut was measured.

---

## Standing rules

- **Nothing published.** Xlaunch does not go to GitHub or a public registry.
  It installs from his machine to his servers.
- **Xlaunch is a fork of Coolify (Apache-2.0).** The derived body stays
  Apache-2.0 and cannot be relicensed. What is licensable to a partner is the
  **Xlaunch mark**, original additions, and the operating work. The gateway is
  original and is licensable on any terms. Do not let the gateway go to the
  infrastructure partner — it holds the metering and the customer relationship.
- **Builder passes** (`projectxprotocol.dev` via Readdy) do ONE of: create at
  most 3 new routes, or change existing files for one concern. Never both.
  Prompt under 400 words. Show the prompt to him before sending it.
- **State lives in things that break when wrong** — tests, migrations, configs,
  commit messages. Not in prose. This file is the only exception, and it gets
  rewritten, not grown.

## Open questions

- 1.0 above: service token vs. session refresh.
- Does a cap stop mid-conversation or at the next request? Gateway behaviour
  needs reading before the UI promises either.
- `gw.projectxprotocol.dev` has no DNS record. Needed before the gateway is
  reachable off-box.

---

*Last updated 2026-09-14. If the code and this file disagree, the code is right
— fix this file in the same commit.*
