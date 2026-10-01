#!/bin/bash
# GridFrames origin lock: the perimeter policy for the OVH host.
#
# Architecture: Cloudflare proxies all web traffic (DDoS filtering, origin-IP
# hiding); the origin answers web ports ONLY from Cloudflare. Docker publishes
# 80/443 through its own iptables chains, which bypass ufw's INPUT rules — the
# effective control is the DOCKER-USER chain, applied here. SSH (22, host
# INPUT, key-only + fail2ban) is intentionally untouched.
#
# Two hard-won rules of this chain:
#   1. --ctdir ORIGINAL on every filter: reply packets from containers share
#      the original dst port; filtering them too takes the platform dark.
#   2. Internal sources are exempt BEFORE the drops: the proxy container
#      originates its own port-443 connections to the tenant VMs (SNI
#      passthrough). External attackers cannot complete TCP handshakes with
#      spoofed private sources, so the exemption does not open the origin.
#
# Idempotent; a failed range fetch keeps the existing rules untouched.
# Deploy: /usr/local/sbin/gf-origin-lock.sh + cron @reboot on the host.

set -uo pipefail   # no -e: partial failures must not abort the chain mid-rewrite

if [ "$(id -u)" -ne 0 ]; then
  exec sudo "$0" "$@"
fi

V4_URL="https://www.cloudflare.com/ips-v4"
V6_URL="https://www.cloudflare.com/ips-v6"
INTERNAL_V4="10.0.0.0/8 172.16.0.0/12 192.168.0.0/16"
INTERNAL_V6="fc00::/7 fe80::/10"
PORTS_TCP="80 443"   # QUIC (443/udp) terminates at Cloudflare; origin speaks tcp
PORTS_UDP="443"

V4_FILE="$(mktemp)"; V6_FILE="$(mktemp)"
trap 'rm -f "$V4_FILE" "$V6_FILE"' EXIT

curl -fsS --max-time 20 "$V4_URL" | grep -E '^[0-9]+\.' > "$V4_FILE" || true
curl -fsS --max-time 20 "$V6_URL" | grep -E '^[0-9a-fA-F]+:' > "$V6_FILE" || true

if [ ! -s "$V4_FILE" ] || [ ! -s "$V6_FILE" ]; then
  echo "FATAL: Cloudflare range fetch failed — existing lock rules left in place." >&2
  exit 1
fi

apply_family() {
  local bin="$1" ranges_file="$2" internal_ranges="$3"
  local chain="DOCKER-USER"
  if ! $bin -L "$chain" >/dev/null 2>&1; then
    return 0   # address family not in use
  fi

  # Drop previous lock rules (tagged by comment), keep everything else.
  while :; do
    local idx
    idx="$($bin -L "$chain" --line-numbers -n 2>/dev/null | awk '/gf-origin-lock/{print $1; exit}')"
    if [ -z "$idx" ]; then break; fi
    $bin -D "$chain" "$idx" 2>/dev/null || break
  done

  # Internal sources exempt first, Cloudflare allows next, drops last.
  local port rng
  for rng in $internal_ranges; do
    $bin -I "$chain" 1 -s "$rng" \
      -m comment --comment "gf-origin-lock" -j RETURN
  done
  for port in $PORTS_TCP; do
    while read -r rng; do
      $bin -I "$chain" 1 -p tcp -s "$rng" \
        -m conntrack --ctorigdstport "$port" --ctdir ORIGINAL \
        -m comment --comment "gf-origin-lock" -j RETURN
    done < "$ranges_file"
    $bin -A "$chain" -p tcp \
      -m conntrack --ctorigdstport "$port" --ctdir ORIGINAL \
      -m comment --comment "gf-origin-lock" -j DROP
  done
  for port in $PORTS_UDP; do
    $bin -A "$chain" -p udp \
      -m conntrack --ctorigdstport "$port" --ctdir ORIGINAL \
      -m comment --comment "gf-origin-lock" -j DROP
  done
}

apply_family iptables "$V4_FILE" "$INTERNAL_V4"
apply_family ip6tables "$V6_FILE" "$INTERNAL_V6"

NV4="$(wc -l < "$V4_FILE")"; NV6="$(wc -l < "$V6_FILE")"
echo "Locked: tcp ${PORTS_TCP} / udp ${PORTS_UDP} answer only from Cloudflare (${NV4} v4 + ${NV6} v6 ranges; internal sources exempt). SSH untouched."
echo "Verify: curl --resolve <domain>:443:<origin-ip> must FAIL; https://<domain> must answer."
