# Operations

What running a selling-mode instance involves, and the things to know before real customers
arrive. Everything here was learned on the reference instance, not assumed.

## Backups

The platform backs up its own database nightly (Settings → Backups, "add the platform database").
The dump lands under `/data/xlaunch/backups`. A backup that only exists on the machine it protects
is not a backup: copy it off the host (object storage, another machine) on the same schedule, and
rehearse a restore. The operator's own accounts, sessions and API tokens are deliberately excluded
from those dumps.

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

Build the new image from the tagged release, push it into the instance's registry, set
`LATEST_IMAGE` and `XLAUNCH_VERSION`, and `docker compose up -d --wait xlaunch`. Database
migrations run at start. Keep the previous image tag in the registry until the new one has served
a day.

## Roadmap, as it stands

Things the product does not do yet, in the order they are planned:

1. **Provisioning machines.** Today a team connects servers it already has, within its tier's
   allowance. The next piece creates a machine for the team on the operator's infrastructure and
   attaches it, so "new machine" is one click.
2. **An agent as the operator's front door.** The customer tells an agent what to run; the agent
   provisions, deploys and opens a shell through the platform's API. The API already exists; the
   agent surface is the work.
3. **A financial ledger in the platform.** Today Stripe is the book of record and the platform
   stores entitlement only (paid or not, which tier). A per-team ledger, invoice history and an
   operator revenue view are planned; until then, accounting lives in the Stripe dashboard.
4. **Payment providers as adapters.** Stripe is the only provider this release implements.
