<?php

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

beforeEach(function () {
    // Symfony keeps trusted-proxy state statically across the shared pest process.
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

afterEach(function () {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

function clientIpAfterMiddleware(array $server): string
{
    $request = Request::create('/api/health', 'GET', [], [], [], $server);
    (new TrustProxies)->handle($request, fn ($r) => response('ok'));

    return $request->getClientIp();
}

test('a spoofed forwarded header from an untrusted source is ignored', function () {
    config()->set('trustedproxy.proxies', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');

    expect(clientIpAfterMiddleware([
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
    ]))->toBe('203.0.113.10');
});

test('a trusted on-host proxy resolves the real client and drops the spoofed entry', function () {
    config()->set('trustedproxy.proxies', '172.16.0.0/12');

    // The proxy appended the real client (rightmost); the leftmost is attacker-chosen.
    expect(clientIpAfterMiddleware([
        'REMOTE_ADDR' => '172.18.0.5',
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7',
    ]))->toBe('198.51.100.7');
});

test('an operator can still opt into trusting every proxy', function () {
    config()->set('trustedproxy.proxies', '*');

    expect(clientIpAfterMiddleware([
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
    ]))->toBe('1.2.3.4');
});
