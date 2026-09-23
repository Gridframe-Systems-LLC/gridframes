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
