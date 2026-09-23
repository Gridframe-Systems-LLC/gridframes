#!/bin/bash
# Switch an installed Xlaunch Deploy into selling mode: accounts, tiers, Stripe, operator console.
# Run as root after install-platform.sh. Reads the Stripe values from the files given; never echoes them.
#   selling-mode.sh <stripe secret key file> <stripe webhook secret file> <personal price id> <team price id>
set -euo pipefail
SK=$(tr -d '\n' < "${1:?stripe key file}"); WH=$(tr -d '\n' < "${2:?webhook secret file}")
PERSONAL=${3:?personal price id}; TEAM=${4:?team price id}
ROOT=/data/xlaunch; ENV_FILE=$ROOT/source/.env
set_env() { if grep -q "^$1=" "$ENV_FILE"; then sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"; else printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"; fi; }
set_env SELF_HOSTED false
set_env SUBSCRIPTION_PROVIDER stripe
set_env STRIPE_API_KEY "$SK"
set_env STRIPE_WEBHOOK_SECRET "$WH"
set_env STRIPE_PRICE_ID_PERSONAL "$PERSONAL"
set_env PLAN_PERSONAL_PRICE '$20'
set_env PLAN_PERSONAL_SERVERS 3
set_env STRIPE_PRICE_ID_TEAM "$TEAM"
set_env PLAN_TEAM_PRICE '$25'
set_env PLAN_TEAM_SERVERS 10
chmod 600 "$ENV_FILE"
docker compose --env-file "$ENV_FILE" -f $ROOT/source/docker-compose.yml -f $ROOT/source/docker-compose.prod.yml up -d --wait --wait-timeout 300 xlaunch >/dev/null
docker exec xlaunch php artisan config:clear >/dev/null
echo "selling mode on: $(docker exec xlaunch php artisan tinker --execute='echo isCloud() ? "cloud" : "self-hosted";' 2>/dev/null | tail -1)"
