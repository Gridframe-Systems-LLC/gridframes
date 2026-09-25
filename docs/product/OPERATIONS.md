# Operations

What running a selling-mode instance involves, and the things to know before real customers
arrive. Everything here was learned on the reference instance, not assumed.

## Machines host: the storage decision, recorded

The host's two NVMe disks are a RAID1 pair that carries the OS, the platform and the ZFS pool;
there is no spare partition. The plan's preference — a dedicated partition — needs an OVH
reinstall or a second disk. The decision taken on 25 September 2026: **run the pool as a bounded
loop-backed ZFS volume (400 GB) inside the RAID1**, sellable disk capped at 330 GB, and treat a
real partition as scheduled work, not an emergency:

- Migrate when allocated disk passes **60% of the pool** (the finance page's capacity gauge shows
  it), or when the second host arrives — whichever first. The second host installs to a real
  partition from the start, and machines move with `incus move`.
- Off-host backups (Phase 9) are what make this safe: a pool that lives in a file is recoverable
  from its backups. Until OVH Object Storage credentials exist, keep the nightly local dumps and
  treat the machines as single-host.

Everything else about the machines host is live as of the same date: Incus 7.4 (Zabbly stable —
the Ubuntu 6.0 package cannot create per-project networks), one ZFS pool, a platform client
certificate trusted by the host, `core.https_address` on the Docker bridge gateway only, and
`/usr/local/sbin/gf-tenant-firewall.sh` on cron fencing every `gf-team-*` bridge (DHCP and DNS in,
egress out through NAT, no tenant-to-tenant, no platform ports, no outbound mail, SSH from the
platform only). The golden image `gf-ubuntu-24.04` carries Docker and sshd and is rebuilt monthly
by `scripts/machines/build-golden-image.sh`; `scripts/machines/host-check.sh` proves a machine end
to end in a throwaway project.

## Backups

The platform can back up its own database nightly (Settings → Backups, "add the platform database").
Independently of that, the host runs a nightly `pg_dump` of its own: the
`xlaunch-db-backup.timer` systemd timer calls `/usr/local/sbin/xlaunch-db-backup.sh`, which dumps
the `xlaunch-db` container into `/data/xlaunch/backups/db` and snapshots the environment file into
`/data/xlaunch/backups/env`, keeping both for 14 days. A backup that only exists on the machine it
protects is not a backup: copy it off the host (object storage, another machine) on the same
schedule, and rehearse a restore. The operator's own accounts, sessions and API tokens are
deliberately excluded from those dumps.

## Mail

Sign-up, invitations, password resets and alerts go out by mail. The host must be able to reach a
mail relay. **DigitalOcean blocks outbound SMTP by default**; OVH and most bare-metal providers do
not. Until mail works, a new customer is held at "verify your email"; the operator can mark an
account verified from the console. Do not open sign-up to the public without working mail.

## Sign-up limits

Sign-up allows 3 attempts per 10 minutes per address and 3 per email per hour, and each failed
attempt counts. The password rule in production is at least 8 characters with upper and lower
case, a number and a symbol, and not a password known from a breach. A person who fails the rule
a few times is locked out for 10 minutes with `429 Too Many Requests`. The locks live in Redis
(`registration:ip:*`, `registration:email-identity:*`) and can be cleared by the operator.

## The API and proxies

The application trusts every proxy (`TrustProxies::$proxies = '*'`), so the address it sees is
whatever `X-Forwarded-For` carries. Two consequences:

- The per-team API allowed-address list is **not** a security control on a public instance,
  because a caller can set that header. Before customers rely on it, pin the trusted proxy to the
  instance's own proxy address in `app/Http/Middleware/TrustProxies.php`.
- On a single-operator instance, keep the API off the internet entirely: `deploy/api-guard.yaml`
  makes the proxy refuse `/api` to everyone but the loopback, and operators reach the API through
  an SSH tunnel to the platform's published port. Do not install that file on a selling-mode
  instance whose customers use the API.

## Tax

Automatic tax at checkout is off by default. Turn it on (`STRIPE_AUTOMATIC_TAX=true`) only after
the Stripe account's tax settings, including a head-office address, are complete; otherwise Stripe
refuses to open a checkout and every customer sees "Unable to confirm checkout".

## Upgrades

Deploys run through GitHub Actions: `deploy-ovh.yml` builds the image from a tag (or by hand from
any ref), ships it over SSH into the host's local registry, points `LATEST_IMAGE` and
`XLAUNCH_VERSION` at it, brings the `xlaunch` service up with `--wait`, runs
`php artisan migrate --force` (the container does not migrate at start by itself), and health
checks `https://gridframes.app/api/health` before it reports success. The database, redis and
realtime services are not touched. It needs the `OVH_SSH_PRIVATE_KEY` repository secret. Pushing
plain commits to `main` builds a `sha-` image and `main` tag on ghcr but deploys nothing. Keep the
previous image tag in the registry until the new one has served a day; rolling back is setting
`LATEST_IMAGE` back and `up -d`.

The host also runs fail2ban against sshd (`/etc/fail2ban/jail.local`); sshd itself is key-only.

## Roadmap, as it stands

Things the product does not do yet, in the order they are planned:

1. **Provisioning machines.** Today a team connects servers it already has, within its tier's
   allowance. The next piece creates a machine for the team on the operator's infrastructure and
   attaches it, so "new machine" is one click. Scoped in Northlatch's GridFrames Machines
   proposal: Incus VMs on the operator's OVH host, "GridFrames machine" as a server source, plan
   pools with billed overage, an SSH gateway and per-machine HTTPS, metering and lifecycle.
2. **An agent as the operator's front door.** The customer tells an agent what to run; the agent
   provisions, deploys and opens a shell through the platform's API. The API already exists; the
   agent surface is the work.
3. **Off-host backups.** The nightly dumps above still live on the host; object storage and a
   rehearsed restore are the missing half.
3. **A financial ledger in the platform.** Today Stripe is the book of record and the platform
   stores entitlement only (paid or not, which tier). A per-team ledger, invoice history and an
   operator revenue view are planned; until then, accounting lives in the Stripe dashboard.
4. **Payment providers as adapters.** Stripe is the only provider this release implements.
