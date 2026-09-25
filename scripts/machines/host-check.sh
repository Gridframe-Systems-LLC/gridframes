#!/bin/bash
# The live check for the machines host: create a 1 GB VM from the golden image in a throwaway
# project, wait for the agent, run docker inside it, confirm a tenant cannot reach the platform's
# ports, then delete everything. Run on the Incus host as root after setup:
#   scripts/machines/host-check.sh
set -euo pipefail
PROJ=host-check
MACHINE=check-vm
PLATFORM_PORT=8000

cleanup() { incus delete --force "$MACHINE" 2>/dev/null || true; incus project delete "$PROJ" 2>/dev/null || true; }
trap cleanup EXIT

incus project create "$PROJ" --description "throwaway: host check" >/dev/null
incus launch gf-ubuntu-24.04 "$MACHINE" --project "$PROJ" --vm -c limits.memory=1GiB -c limits.cpu=1

echo -n "waiting for the guest agent and an address"
for attempt in $(seq 1 60); do
  ADDR=$(incus list "$MACHINE" --project "$PROJ" --format csv -c 4 | grep -oE 'inet6?\s+[0-9a-fA-F:.]+' | head -1 | awk '{print $2}')
  [ -n "$ADDR" ] && break
  echo -n "."; sleep 2
done
echo " address: ${ADDR:-none}"
[ -n "$ADDR" ] || { echo "FAIL: no address"; exit 1; }

echo "docker inside: $(incus exec "$MACHINE" --project "$PROJ" -- docker version --format '{{.Server.Version}}')"

echo -n "platform port from inside the machine: "
if incus exec "$MACHINE" --project "$PROJ" -- timeout 3 bash -c "curl -s -o /dev/null -w '%{http_code}' http://$(ip -4 route show default | awk '{print $3}'):${PLATFORM_PORT}" 2>/dev/null | grep -qE '[0-9]'; then
  echo "FAIL: the platform's port answered from a tenant machine"; exit 1
fi
echo "unreachable, as it must be"

echo "PASS"
