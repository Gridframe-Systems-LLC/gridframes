#!/bin/bash
# Install the Xlaunch Deploy platform on a fresh Ubuntu server, from source that is already on the
# server and images that are already in the server's own registry. Run as root.
#
#   install-platform.sh <source dir> <registry> <platform version> <helper version>
#   install-platform.sh /opt/xlaunch-src/platform localhost:5000 4.3.19 1.0.16
#
# It lays out /data/xlaunch the way the platform's own installer does, generates the instance secrets
# once (an existing .env is kept, never regenerated), gives the platform an SSH key to manage this
# host, and starts the production stack. Until the dashboard has its public address, it is reached
# through an SSH tunnel to port 8000; the cloud firewall keeps that port closed to the internet.
set -euo pipefail

SRC=${1:?source dir}; REGISTRY=${2:?registry}; VERSION=${3:?platform version}; HELPER=${4:?helper version}
ROOT=/data/xlaunch
ENV_FILE=$ROOT/source/.env

[ "$(id -u)" = "0" ] || { echo "run as root" >&2; exit 1; }
for f in docker-compose.yml docker-compose.prod.yml .env.production; do
  [ -f "$SRC/$f" ] || { echo "missing $SRC/$f" >&2; exit 1; }
done

mkdir -p $ROOT/{source,ssh,applications,databases,backups,services,proxy,sentinel,images}
mkdir -p $ROOT/ssh/{keys,mux} $ROOT/proxy/dynamic
cp "$SRC/docker-compose.yml" "$SRC/docker-compose.prod.yml" "$SRC/.env.production" $ROOT/source/

set_env() { # key value: replace the line when present, append when not
  local key=$1 value=$2
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
  else
    printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
  fi
}
fill_once() { # key value: only when the key is absent or empty, so a re-run never rotates a secret
  local key=$1 value=$2
  if ! grep -q "^${key}=.\+" "$ENV_FILE"; then set_env "$key" "$value"; fi
}

if [ ! -f "$ENV_FILE" ]; then cp $ROOT/source/.env.production "$ENV_FILE"; fi
chmod 600 "$ENV_FILE"
fill_once APP_ID "$(openssl rand -hex 16)"
fill_once APP_KEY "base64:$(openssl rand -base64 32)"
fill_once DB_PASSWORD "$(openssl rand -hex 32)"
fill_once REDIS_PASSWORD "$(openssl rand -hex 32)"
fill_once PUSHER_APP_ID "$(openssl rand -hex 32)"
fill_once PUSHER_APP_KEY "$(openssl rand -hex 32)"
fill_once PUSHER_APP_SECRET "$(openssl rand -hex 32)"
set_env APP_NAME Xlaunch
set_env APP_ENV production
set_env APP_DEBUG false
set_env REGISTRY_URL "$REGISTRY"
set_env LATEST_IMAGE "$VERSION"
set_env XLAUNCH_VERSION "$VERSION"
# The compose file publishes the dashboard on 8000 and realtime on 6001-6002 and cannot carry a bind
# address. They stay closed to the internet by the cloud firewall, which admits only 22, 80 and 443;
# the proxy serves the dashboard publicly once its address is set.
sed -i '/^APP_PORT=/d; /^SOKETI_PORT=/d' "$ENV_FILE"

# The platform manages this host over SSH as root, with a key only it holds.
KEY=$ROOT/ssh/keys/id.root@host.docker.internal
if [ ! -f "$KEY" ]; then
  ssh-keygen -t ed25519 -a 100 -f "$KEY" -q -N "" -C xlaunch
fi
mkdir -p /root/.ssh && touch /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys
grep -qF "$(cat "$KEY.pub")" /root/.ssh/authorized_keys || cat "$KEY.pub" >> /root/.ssh/authorized_keys
chown -R 9999:root $ROOT
chmod -R 700 $ROOT/ssh

docker network inspect xlaunch >/dev/null 2>&1 || docker network create --attachable xlaunch >/dev/null
docker pull -q "$REGISTRY/northlatch/xlaunch-helper:$HELPER" >/dev/null

docker compose --env-file "$ENV_FILE" \
  -f $ROOT/source/docker-compose.yml -f $ROOT/source/docker-compose.prod.yml \
  up -d --remove-orphans --wait --wait-timeout 300

docker ps --format '{{.Names}}\t{{.Status}}' | grep -E '^xlaunch'
