# Xlaunch — Revenue Model

**Owner:** Northlatch Labs LLC
**Status:** Draft v1 (business foundation — see "Capability honesty" for what exists in code today vs. what requires build-out)

## 1. Product thesis

Xlaunch is a deployment control plane: an open-source PaaS layer that turns any set of servers (cloud VMs, bare metal, datacenter racks) into a deployment platform with git-push-style UX, one-click services, SSL, proxies, backups, and multi-server orchestration.

The open-core fork it is built on (Coolify, Apache-2.0) has proven product–market fit in the self-hosted market. Northlatch Labs LLC's business is to **monetize the operation of this platform**, not the source code itself: the code is free; running it well, at scale, and with enterprise guarantees is the paid product. This is the same wedge used by every successful infrastructure open-core company (the code is free, the operation is not).

## 2. Revenue lines

| # | Revenue line | What the customer pays for | Margin profile |
|---|---|---|---|
| 1 | **Xlaunch Cloud** — hosted control plane | A managed, multi-tenant SaaS version of the control plane; customers connect their own servers | Software margin (~90%+); cost = control-plane infra + support |
| 2 | **Managed datacenter fleets** | Xlaunch provisions and operates servers (Hetzner/DO/AWS/bare metal) as full deployment platforms; customer pays usage + platform fee | Compute resale spread (~30–60% on cloud; higher on owned bare metal/datacenter capacity) |
| 3 | **Enterprise tier** | SSO/SAML, audit logs, RBAC hardening, SLAs, priority support, private deployments | Services + license margin |
| 4 | **Template marketplace** | Paid one-click app/service templates; rev-share with template authors | 80/20–90/10 split |
| 5 | **White-label / OEM** | Agencies and MSPs rebrand Xlaunch for their own customers | License + support margin |

Line 2 is the "data center provider like Vercel" ambition: the control plane already manages arbitrary remote servers over SSH (inherited capability); the business work is provisioning automation, fleet quotas, metering, and billing.

## 3. Draft pricing (to validate with launch data)

| Tier | Target | Price (draft) | Includes |
|---|---|---|---|
| Community | Self-hosters | $0 | Full open-source control plane, self-managed; community support |
| Cloud Pro | Teams/indie SaaS | ~$20–49 / user / month | Hosted control plane, unlimited connected servers at fair-use, previews, team features |
| Cloud Scale | Startups/SMBs | usage-based | Line 2 fleets: compute at metered rate + platform fee, autoscaling groups |
| Enterprise | Orgs | Custom | SSO/SAML, audit, SLA, dedicated support, on-prem/private cloud |

Positioning rule: the Community tier must remain genuinely useful (Apache-2.0 obligation and growth engine). Paid value concentrates on **operation, scale, compliance, and fleet economics** — not on artificially crippled code.

## 4. Unit economics notes

- Control plane (line 1) is classic SaaS: cost per tenant is small; seats are nearly pure margin.
- Fleet resale (line 2) must be priced off **metered usage**, not flat seats: raw compute cost + 35–60% platform spread, with per-server platform fee covering management overhead (patching, monitoring, proxy, backups).
- Current codebase contains **no production billing or metering** — this is the single largest build-out item (Roadmap Phase 2) and gates revenue lines 1–5.
- Marketplace and OEM require licensing/tenancy features that do not exist yet; do not sell them before Phase 4.

## 5. Capability honesty

| Capability | State in this codebase (observed at fork time) |
|---|---|
| Multi-server deployment, SSL, proxies, one-click services, backups, API, webhooks | **Exists** (inherited from upstream) |
| Teams / multi-user | **Exists** (inherited); tenancy hardening for commercial multi-tenant operation = build-out |
| Cloud provider integrations (Hetzner, DigitalOcean, AWS signals in code) | **Partial** — provisioning automation needs productization |
| OAuth login (Socialite signals) | **Partial** — OAuth providers present; enterprise SSO/SAML = build-out |
| Billing / metering / Stripe-grade monetization | **Build-out** (Stripe string references exist but are not a confirmed billing system — verify before any claim) |
| Marketplace / OEM licensing | **Build-out** |

## 6. Go-to-market sequence

1. **Boot:** publish own images, run internal production instance, validate E2E (Roadmap Phase 1).
2. **Design partners:** 5–10 design partners on Xlaunch Cloud (line 1) with manual invoicing — revenue before billing automation exists.
3. **Launch:** public Cloud launch with Stripe billing (Phase 2 complete).
4. **Fleets:** open managed datacenter line to Scale customers (Phase 3).
5. **Ecosystem:** marketplace + OEM (Phase 4).

## 7. Legal guardrails

- Apache-2.0 compliance is non-negotiable: retain `LICENSE`, `NOTICE.md`, and attribution in every distribution channel (source, images, docs).
- Do not use the "Coolify" name, logo, or trademarks in marketing, domains, or UI.
- Track upstream for security patches (the `upstream` git remote) — shipping known CVEs is a revenue and liability risk.
