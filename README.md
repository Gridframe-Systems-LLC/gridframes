# Xlaunch

**Xlaunch** is an open-source deployment control plane: connect your servers and deploy anything — apps, databases, one-click services — with git-push-style UX, automatic SSL, proxies, previews, and multi-server orchestration.

Xlaunch is developed and operated by **Northlatch Labs LLC** as the foundation of Xlaunch Cloud (hosted control plane) and Xlaunch managed fleets (fully operated deployment platforms).

## Quickstart (self-host)

> Placeholder URL — the production install path is published to the Northlatch distribution host after image publication (see `REBRAND.md` bootstrap checklist).

```bash
curl -fsSL https://northlatch.com/xlaunch/install.sh | bash
```

Then open the UI on port 8000 of your server and follow the onboarding.

## What you get (inherited core)

- Any language / any framework deployments via Docker, Nixpacks, or custom Dockerfiles
- One-click services (databases, queues, caches, and 100+ templates)
- Automatic SSL, reverse proxy (Traefik/Caddy), and custom domains
- Multi-server orchestration over SSH (Swarm-ready)
- Git-based deployments with preview environments
- Scheduled tasks, backups (S3-compatible), notifications, and a full REST API

## Documentation

- Docs: `https://northlatch.com/docs` *(placeholder — see REBRAND.md)*
- Business model: [`docs/business/REVENUE_MODEL.md`](./docs/business/REVENUE_MODEL.md)
- Platform roadmap: [`docs/business/ENTERPRISE_ROADMAP.md`](./docs/business/ENTERPRISE_ROADMAP.md)
- Rebrand & fork record: [`REBRAND.md`](./REBRAND.md)

## Security

Report vulnerabilities to `hello@northlatch.com` *(placeholder — see REBRAND.md)*. See `SECURITY.md`.

## Attribution

Xlaunch is based on [Coolify](https://github.com/coollabsio/coolify), licensed under the Apache License 2.0. See [`NOTICE.md`](./NOTICE.md) and [`LICENSE`](./LICENSE).
