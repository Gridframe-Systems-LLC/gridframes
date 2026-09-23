# Install

From a fresh Ubuntu 24.04 server to a running Xlaunch Deploy in selling mode. Root or sudo on the
server; nothing else. Every command here was run to produce the reference instance.

## 1. Prepare the server

```bash
apt-get update && apt-get install -y docker.io docker-compose-v2 unattended-upgrades
systemctl enable --now docker
mkdir -p /data/registry
docker run -d --restart unless-stopped --name registry -p 127.0.0.1:5000:5000 \
  -v /data/registry:/var/lib/registry registry:2
```

Open only ports 22, 80 and 443 at the host's firewall. The platform publishes its dashboard on
port 8000 and its realtime service on 6001–6002; both stay closed to the internet and are served
through the proxy once the instance has its address.

## 2. Put the images in the server's registry

The platform pulls its own images from the registry named in its configuration. Build them from
this repository, or load the images a release ships, then tag and push them into the local
registry:

```bash
# built from this repository, at the tagged release you are installing
docker build -f docker/production/Dockerfile -t localhost:5000/northlatch/xlaunch:<version> .
docker push localhost:5000/northlatch/xlaunch:<version>
# the two helper images the release names (see versions.json)
docker push localhost:5000/northlatch/xlaunch-helper:<helper version>
docker push localhost:5000/northlatch/xlaunch-realtime:<realtime version>
```

## 3. Install the platform

`deploy/install-platform.sh` lays out `/data/xlaunch`, generates the instance secrets once, gives
the platform an SSH key to manage the host, and starts the stack. It is safe to re-run; an
existing `.env` is kept and secrets are never regenerated.

```bash
cp -r . /opt/xlaunch-src/platform
deploy/install-platform.sh /opt/xlaunch-src/platform localhost:5000 <version> <helper version>
```

When it finishes, `docker ps` shows `xlaunch`, `xlaunch-db`, `xlaunch-redis` and
`xlaunch-realtime` healthy.

## 4. Switch on selling mode

Put the Stripe secret key and the webhook signing secret in two root-only files (they are never
echoed), then:

```bash
deploy/selling-mode.sh /root/stripe-key.txt /root/stripe-webhook.txt <personal price id> <team price id>
```

This sets `SELF_HOSTED=false`, the Stripe values and the two tiers, restarts the platform and
prints `selling mode on: cloud`. Adjust allowances and price labels afterwards in `.env` (see
`CONFIGURATION.md`).

Create the Stripe webhook endpoint at `https://<your host>/webhooks/payments/stripe/events` with
the events `checkout.session.completed`, `customer.subscription.created`,
`customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid` and
`invoice.payment_failed`.

## 5. The operator account, the address, and sign-up

The root operator is seeded from the environment. Pass the values straight into the container so
they never land in a shell history; the password must have upper and lower case, a number and a
symbol:

```bash
docker exec -e ROOT_USER_EMAIL=you@example.com -e ROOT_USERNAME='Your name' \
  -e ROOT_USER_PASSWORD='...' xlaunch php artisan db:seed --class=RootUserSeeder --force
```

Seeding the root user switches public sign-up off. Set the instance's address, switch sign-up
back on, point the platform at its registry and start the proxy:

```bash
docker exec xlaunch php artisan tinker --execute='
  $s = \App\Models\InstanceSettings::get();
  $s->fqdn = "https://deploy.example.com"; $s->is_registration_enabled = true;
  $s->docker_registry_url = "localhost:5000"; $s->save();
  \App\Models\Server::find(0)->setupDynamicProxyConfiguration();
  \App\Actions\Proxy\StartProxy::run(\App\Models\Server::find(0), false);'
```

Point the DNS name at the server. The proxy obtains a Let's Encrypt certificate on the first
request; `https://<your host>/login` answers 200 within a minute or two.

## 6. Check

- `https://<host>/login` and `/register` answer 200 with a valid certificate.
- Sign up as a customer in a private window; `/subscription/new` shows the tiers; checkout opens
  Stripe's page; after paying, the team's server allowance equals the tier's.
- `/admin`, signed in as the operator, lists the customer's team.

Mail: sign-up sends a verification email. The host must be able to send mail (see
`OPERATIONS.md`, "Mail"); until it can, verify accounts from the operator console.
