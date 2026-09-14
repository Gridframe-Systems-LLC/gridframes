# Xlaunch — Enterprise Roadmap

**Owner:** Northlatch Labs LLC
**Status:** Draft v1. Each item is marked: **[exists]** inherited and verified at fork time · **[partial]** present but needs productization · **[new]** must be built.

## Phase 0 — Fork & brand ownership (COMPLETE at this commit)

- [x] Apache-2.0 fork established with upstream provenance (`upstream` git remote pinned to coollabsio/coolify@59eacc9ff8362e007104cd3549b3f4518ba70ff4)
- [x] Full rebrand to Xlaunch / Northlatch Labs LLC (see `REBRAND.md` for the complete map and leftover audit)
- [x] `LICENSE` retained verbatim; `NOTICE.md` added; no upstream trademark artifacts shipped
- [x] CI ownership: image builds retargeted to `ghcr.io/northlatch/*`

## Phase 1 — Own-image bootstrap (target: week 1–2)

- [ ] **[new]** Publish `ghcr.io/northlatch/xlaunch`, `-helper`, `-realtime` from CI; verify all three run
- [ ] **[new]** End-to-end deployment validation: fresh server → install script → first app deployed → SSL issued
- [ ] **[new]** Upgrade-path test (in-app upgrade against our registry)
- [ ] **[partial]** Install script CDN path: replace placeholder domain with the real distribution host
- [ ] **[new]** Replace placeholder logo assets with final brand design
- [ ] **[new]** Smoke suite in CI (deploy/destroy/rollback of a trivial app)

## Phase 2 — Commercial core: billing & tenancy (target: month 1–3) — gates all revenue

- [ ] **[new]** Stripe billing: subscriptions, seat management, plan gating, dunning, invoices
- [ ] **[new]** Usage metering service (per-server hours, build minutes, bandwidth) — feeds fleets pricing
- [ ] **[partial]** Multi-tenant hardening on the inherited teams model: tenant isolation review, resource quotas, per-tenant rate limits
- [ ] **[partial]** RBAC granularity beyond inherited roles
- [ ] **[new]** Audit log (immutable, exportable) — enterprise checkbox
- [ ] **[partial]** SSO: OAuth exists (Socialite); add SAML/OIDC enterprise gate
- [ ] **[new]** Admin analytics (tenants, servers, deploys, revenue-facing metrics)

## Phase 3 — Datacenter provider mode (target: month 3–6)

- [ ] **[partial]** API-driven server provisioning (Hetzner/DO/AWS signals exist in code) → one-click fleet creation with cloud-init automation
- [ ] **[new]** Bare-metal / own-datacenter onboarding path (this is the data-center-provider differentiator)
- [ ] **[new]** Fleet quotas, capacity reservations, per-tenant server pools
- [ ] **[new]** GPU node class for AI workloads (label scheduling via existing proxy/orchestration layer)
- [ ] **[partial]** Fleet monitoring/alerting (Sentry integration exists for app errors; infra metrics need build-out)
- [ ] **[new]** Bandwidth/egress metering integration with Phase 2 billing

## Phase 4 — Ecosystem (month 6+)

- [ ] **[new]** Template marketplace with rev-share and submission pipeline
- [ ] **[new]** Public API v2 + CLI (`xlaunch` CLI) + SDK
- [ ] **[new]** White-label/OEM licensing tier (branding flags, licensing server)
- [ ] **[new]** Compliance packaging: SOC 2 Type I path if enterprise demand warrants

## Security & compliance (continuous)

- **[exists]** Track upstream via `upstream` remote; triage and merge security patches weekly
- **[new]** Dependency scanning (composer audit + npm audit + container scanning) in CI
- **[new]** Pre-launch penetration test on control plane + agent channel (SSH path)
- **[new]** Secrets handling review: no customer secrets in logs; encrypted at rest (verify inherited behavior)

## KPIs to instrument from Phase 1

Deploy success rate, time-to-first-deploy (TTFD), server uptime, upgrade failure rate, trial→paid conversion, fleet gross margin per server.
