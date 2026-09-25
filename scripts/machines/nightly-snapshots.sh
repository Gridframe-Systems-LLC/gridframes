#!/bin/bash
# Nightly local snapshot of every GridFrames machine, kept 7 days. Local insurance until the
# off-host backup target is wired — not a substitute for it. Run on the Incus host as root;
# on the reference host cron runs it at 04:00 UTC from /etc/cron.d/gf-nightly-snapshots.
set -uo pipefail
STAMP=$(date +%F)
for NAME in $(incus list --format csv -c n 2>/dev/null | grep "^gf-"); do
  incus snapshot create "$NAME" "nightly-$STAMP" --expiry 7d >/dev/null 2>&1 || true
done
