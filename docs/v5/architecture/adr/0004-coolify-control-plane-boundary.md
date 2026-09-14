# ADR 0004: Keep Xlaunch Laravel as the product control plane

## Status

Accepted.

## Context

Xlaunch v5 manages user intent across teams, projects, environments,
applications, services, databases, servers, domains, deployments, secrets,
notifications, billing, and audit history. Those concepts require durable state,
RBAC, validation, UI/API workflows, and user-facing status.

The v5 runtime also needs Flux and coold so hosts behind NAT can receive work and
privileged host operations stay close to the host. If product decisions move into
Flux or coold, the system gains multiple control planes with duplicated rules and
unclear ownership. If Xlaunch directly mutates hosts, the Flux/coold boundary is
bypassed and host safety checks become optional.

## Decision

Xlaunch Laravel is the product control plane for v5. It owns user intent,
durable product state, RBAC, API tokens, sessions, SSO/OAuth, projects,
environments, resources, deployment state machines, placement decisions, proxy
and ingress intent, secret resolution, notifications, billing/subscriptions,
business audit, deployment logs, and user-facing status.

Xlaunch chooses what should happen and which host should receive the work. It
then turns product intent into ordered host primitives and dispatches them
through Flux to coold. Xlaunch records primitive results and advances the durable
resource or deployment state.

Xlaunch must not hold long-lived coold streams or directly expose privileged host
runtime sockets as part of normal v5 operation. It also must not rely on Flux or
coold to understand product concepts such as teams, applications, domains,
rollbacks, billing, or audit meaning.

## Consequences

### Positive

- Product behavior has one durable source of truth.
- RBAC, validation, audit, and user-facing status stay near the UI/API and
  database model.
- Flux and coold remain reusable infrastructure boundaries instead of becoming
  hidden product layers.
- Deployment state machines can be tested against typed primitive results rather
  than raw host side effects.

### Negative

- Xlaunch must model enough deployment state to recover from partial host
  failures and retries.
- New user-facing features may require both product changes in Xlaunch and new
  primitives in coold.
- Xlaunch cannot shortcut missing primitives by using raw host access without
  weakening the architecture boundary.

## Boundary rules

- If the decision involves a user, team, project, environment, resource,
  deployment, domain, secret, notification, billing event, or audit record,
  Xlaunch owns it.
- If the decision is which connected host should receive work, Xlaunch chooses
  and Flux routes.
- If the work is a concrete host operation, Xlaunch dispatches an explicit
  primitive instead of mutating the host directly.
- If Xlaunch cannot express a required host operation as an existing primitive,
  the protocol needs a reviewed primitive addition instead of a product-layer
  bypass.
