<?php

// Whose X-Forwarded-* headers may be trusted. The production instance sits behind
// its own on-host reverse proxy on a docker network, so the default trusts loopback
// and RFC1918 space only: a client-supplied X-Forwarded-For from any other source
// is ignored, which keeps rate-limit keys and the API IP allowlist unspoofable.
// A deployment fronted by a CDN adds its ranges via TRUSTED_PROXIES; '*' restores
// the previous trust-everything behavior if ever explicitly wanted.
return [
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'),
];
