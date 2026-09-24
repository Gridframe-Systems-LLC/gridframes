# Configuration

Everything the product reads from its environment (`/data/xlaunch/source/.env`). Values marked
*secret* must never be committed, logged or echoed. After changing a value, restart the `xlaunch`
container and run `php artisan config:clear` inside it.

## Mode

| Key | Meaning |
| --- | --- |
| `SELF_HOSTED` | `false` = selling mode (accounts, tiers, operator console). `true` = single operator. Default `true`. |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` on any instance customers reach. |
| `REGISTRY_URL` | The registry the platform pulls its own images from, e.g. `localhost:5000`. |
| `LATEST_IMAGE`, `XLAUNCH_VERSION` | The platform image version to run. |

## Tiers and payments (selling mode)

| Key | Meaning |
| --- | --- |
| `SUBSCRIPTION_PROVIDER` | `stripe`. The only provider this release implements. |
| `STRIPE_API_KEY` *secret* | Stripe secret key, test (`sk_test_…`) or live (`sk_live_…`). |
| `STRIPE_WEBHOOK_SECRET` *secret* | Signing secret of the webhook endpoint at `/webhooks/payments/stripe/events`. |
| `STRIPE_PRICE_ID_PERSONAL` | Stripe price id of the Personal tier. Unset = the tier is not on sale. |
| `PLAN_PERSONAL_PRICE` | The price as shown on the page, e.g. `$20`. Display only; Stripe charges the price id's amount. |
| `PLAN_PERSONAL_SERVERS` | Machines the tier grants a team. `0` = not on sale. |
| `STRIPE_PRICE_ID_TEAM`, `PLAN_TEAM_PRICE`, `PLAN_TEAM_SERVERS` | The same for the Team tier. Shown as "per month, per member". |
| `STRIPE_AUTOMATIC_TAX` | `true` turns on Stripe Tax and tax-id collection at checkout. Requires the Stripe account's tax settings to be complete, or every checkout fails. Default `false`. |
| `STRIPE_PRICE_ID_DYNAMIC_MONTHLY`, `…_YEARLY` | The inherited per-server price. Not shown on the tier page; kept for existing subscriptions. |

A Stripe price for a tier must carry the lookup key the tier is matched by:
`xlaunch_deploy_personal` and `xlaunch_deploy_team`. The webhook uses the lookup key, so a price
can be replaced without touching the instance.

## Operator account

| Key | Meaning |
| --- | --- |
| `ROOT_USER_EMAIL`, `ROOT_USERNAME`, `ROOT_USER_PASSWORD` *secret* | Read once by `RootUserSeeder`. Pass them into the container for that one command rather than writing them to `.env`. |

## Mail

Selling mode verifies customer email addresses and sends invitations and notifications. Configure
Laravel's mailer (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
*secret*, `MAIL_FROM_ADDRESS`) against a relay the host can reach. Some cloud providers block
outbound mail ports by default; see `OPERATIONS.md`.

## Instance settings (in the database, via the operator console)

Public address (`fqdn`), whether sign-up is open, the registry the platform pulls from, the API
allowed-address list, and the platform's own backup schedule live in Settings, not in `.env`.

## The public landing page

A selling-mode instance serves a landing page at its apex; a self-hosted instance sends the visitor
straight to sign-in. Every value below is read at request time, so rebranding is an environment
change and a restart, never a patch.

| Variable | Default | What it does |
|---|---|---|
| `LANDING_ENABLED` | on when `SELF_HOSTED=false` | Serves the landing at `/`. When off, `/` redirects to sign-in exactly as before. |
| `LANDING_BRAND` | `Xlaunch Deploy` | The name in the masthead, the footer and the page title. |
| `LANDING_LEGAL_NAME` | empty | The entity named in the copyright line. Falls back to the brand. |
| `LANDING_HEADLINE` | see `config/landing.php` | The first line a visitor reads. |
| `LANDING_SUBHEAD` | see `config/landing.php` | The paragraph under it, and the page's meta description. |
| `LANDING_REGION` | empty | Where the machines run, e.g. `Gravelines, France`. Omitted from the page when empty. |
| `LANDING_DOCS_URL` | empty | Adds a documentation link. An empty value removes the link rather than printing a dead one. |
| `LANDING_STATUS_URL` | empty | Adds a status page link. |
| `LANDING_TERMS_URL` | empty | Adds a terms link. |
| `LANDING_PRIVACY_URL` | empty | Adds a privacy link. |
| `LANDING_SUPPORT_EMAIL` | empty | Adds a contact link. |
| `LANDING_INDEXABLE` | `false` | Allows search engines to index the page. Leave off for a private demo. |

The price, the machine allowance and the machine specification on the page come from the tier
settings in the table above this section, not from the landing configuration. A visitor is therefore
quoted the same figure the checkout charges.

| Variable | Example | What it does |
|---|---|---|
| `PLAN_PERSONAL_MACHINE` | `2 vCPU · 8 GB RAM · 80 GB NVMe` | The specification shown for the Personal tier. Separate facts with `·`. |
| `PLAN_TEAM_MACHINE` | `4 vCPU · 16 GB RAM · 160 GB NVMe` | The same for the Team tier. |

A tier with no Stripe price id is never shown on the landing page, because it cannot be bought.

### Where the dashboard moved

The apex belongs to the public, so a signed-in operator's dashboard is at `/dashboard`. Visiting `/`
with a session redirects there, and every link inside the application follows the route name, so no
bookmark breaks.

## Tiers, trial and paying with credits

Four tiers ship, cheapest first; the landing page quotes the first one on sale as its "from" price.
A tier is on sale when it grants machines and can be paid: by card when it has a Stripe price, by
gateway credits when it has a price in cents and the instance is connected to a gateway.

| Variable | Example | What it does |
|---|---|---|
| `STRIPE_PRICE_ID_AGENT_1GB`, `_AGENT_2GB`, `_PERSONAL`, `_TEAM` | `price_…` | The Stripe price for each tier. |
| `PLAN_<TIER>_CENTS` | `600` | The monthly price in cents a credit payment takes. Keep it equal to the Stripe price: the finance page compares them. |
| `PLAN_<TIER>_PRICE` | `$6` | The label shown. Left empty, it is derived from the cents. |
| `PLAN_<TIER>_SERVERS` | `1` | Machines the tier grants the team. Zero keeps the tier off sale. |
| `PLAN_<TIER>_MACHINE` | `shared vCPU · 1 GB RAM` | The specification line. Separate facts with `·`. |
| `PLAN_TRIAL_DAYS` | `30` | Free days before the first payment, once per team, for card and credit payers alike. `0` is no trial. |
| `GATEWAY_URL` | `https://gateway.xlaunch.work` | The Xlaunch gateway whose credits can pay for plans. |
| `GATEWAY_PLATFORM_TOKEN` | secret | The platform token that gateway issued (`XLAUNCH_PLATFORM_SERVICE_TOKEN` on its side). Both this and the URL must be set to take credit payments. |
| `PLAN_CREDITS_GRACE_DAYS` | `3` | Days a credit-paid plan keeps working after a renewal the balance could not cover. |
| `LICENSE_REVENUE_SHARE_PERCENT` | `30` | The licensor's share, shown on the finance page. Unset hides the line. |

A customer pays with credits by giving one of their gateway API keys once; the platform uses it to
find the account and keeps only the account id. Credit-paid plans renew hourly from the moment their
paid period ends, charged once per period under a reference the gateway will not take twice.

The operator's finance page is at `/admin/finance`: plans in force by how they pay, monthly
recurring revenue, card and credit revenue this month, the licence share, trials ending, refused
renewals, and a check that every configured price still matches Stripe.
