#!/bin/bash
# Build the GridFrames golden VM image: the Ubuntu 24.04 cloud image, Docker from Docker's own apt
# repository, qemu-guest-agent, nothing else. The result is published as the alias this host names
# by MACHINES_IMAGE (default gf-ubuntu-24.04); new machines are clones of it, so they arrive with a
# working Docker and no install step. Rebuild monthly for security updates; machines already running
# are not touched by a rebuild.
#
# Run on the Incus host as root:  scripts/machines/build-golden-image.sh [alias]
set -euo pipefail
ALIAS=${1:-gf-ubuntu-24.04}
TMP=golden-build

incus delete --force "$TMP" 2>/dev/null || true
incus launch images:ubuntu/24.04 "$TMP" --vm -c limits.memory=2GiB

echo -n "waiting for the guest agent"
for attempt in $(seq 1 90); do
  if incus exec "$TMP" -- true >/dev/null 2>&1; then break; fi
  echo -n "."; sleep 2
done
echo
incus exec "$TMP" -- bash -s <<'INSIDE'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
rm -f /etc/resolv.conf
printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\n' > /etc/resolv.conf
apt_ready=false
for attempt in 1 2 3 4 5 6; do
  if apt-get update -qq; then apt_ready=true; break; fi
  echo "apt not ready (attempt $attempt); waiting"; sleep 10
done
$apt_ready || { echo "apt never became ready"; exit 1; }
apt-get install -y -qq ca-certificates curl qemu-guest-agent
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu noble stable" > /etc/apt/sources.list.d/docker.list
apt-get update -qq
apt-get install -y -qq docker-ce docker-ce-cli containerd.io
systemctl enable --now docker qemu-guest-agent
echo "docker server: $(docker version --format '{{.Server.Version}}')"
INSIDE

incus stop "$TMP" --force
incus publish "$TMP" --alias "$ALIAS" --reuse "description=GridFrames golden image: Ubuntu 24.04, Docker, guest agent"
incus delete --force "$TMP"
echo "published: $(incus image list "$ALIAS" --format csv -c f)"
