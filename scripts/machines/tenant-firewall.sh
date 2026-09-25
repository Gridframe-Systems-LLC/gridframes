#!/bin/bash
# The tenant firewall for GridFrames machines: applied to every gf-team-* bridge on the host.
# Idempotent (ufw dedupes identical rules), so cron runs it every minute and a bridge created by
# the platform is fenced within a minute of existing:
#   - DHCP and DNS from the bridge to the host answer (any client source, including 0.0.0.0);
#   - tenants cannot reach the platform's ports or anything private (other bridges, the host,
#     RFC1918 and the IPv6 ULA block fc00::/7 — the dual-stack catch-all below must not become
#     the door between tenant bridges over IPv6);
#   - no outbound mail (25);
#   - everything else forwards out through NAT;
#   - the platform's own containers may reach every machine on SSH (22).
# Rules are added in this order per bridge: ufw is first-match for filter rules.
set -euo pipefail

for BR in $(ls /sys/class/net | grep -E '^gf-team-' || true); do
  ufw allow in on "$BR" to any port 67 proto udp >/dev/null 2>&1 || true
  ufw allow in on "$BR" to any port 53 >/dev/null 2>&1 || true
  ufw deny in on "$BR" to any port 25 proto tcp >/dev/null 2>&1 || true
  ufw deny in on "$BR" to 10.0.0.0/8 >/dev/null 2>&1 || true
  ufw deny in on "$BR" to 172.16.0.0/12 >/dev/null 2>&1 || true
  ufw deny in on "$BR" to 192.168.0.0/16 >/dev/null 2>&1 || true
  # fc00::/7 covers every bridge's auto-assigned fd42::/64 ULA and the host's ULA addresses.
  ufw deny in on "$BR" to fc00::/7 >/dev/null 2>&1 || true
  ufw route deny in on "$BR" to 10.0.0.0/8 >/dev/null 2>&1 || true
  ufw route deny in on "$BR" to 172.16.0.0/12 >/dev/null 2>&1 || true
  ufw route deny in on "$BR" to 192.168.0.0/16 >/dev/null 2>&1 || true
  ufw route deny in on "$BR" to fc00::/7 >/dev/null 2>&1 || true
  ufw route deny in on "$BR" to any port 25 proto tcp >/dev/null 2>&1 || true
  ufw route allow in on "$BR" >/dev/null 2>&1 || true
done

# The platform lives in the Docker bridges; its validation and deploy path needs SSH to the machines.
ufw route allow from 172.16.0.0/12 to any port 22 proto tcp >/dev/null 2>&1 || true
