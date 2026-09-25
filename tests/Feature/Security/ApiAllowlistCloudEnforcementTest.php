<?php

use App\Http\Middleware\ApiAllowed;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function runApiAllowed(string $remoteAddr): int
{
    $request = Request::create('/api/v1/version', 'GET', [], [], [], [
        'REMOTE_ADDR' => $remoteAddr,
    ]);
    $response = (new ApiAllowed)->handle($request, fn () => response()->json(['ok' => true]));

    return $response->getStatusCode();
}

beforeEach(function () {
    // The public production instance runs in cloud mode; the allowlist must bind there.
    config()->set('constants.xlaunch.self_hosted', false);
    InstanceSettings::forceCreate(['id' => 0]);
    // instanceSettings() memoizes via once() for the whole pest process; earlier test
    // files leave a memo pointing at a rolled-back database. Flush it so the
    // middleware reads this test's settings.
    \Illuminate\Support\Once::flush();
});

test('a cloud instance enforces the API allowlist against every source', function () {
    $settings = instanceSettings();
    $settings->update(['is_api_enabled' => true, 'allowed_ips' => null]);

    // An explicit allowlist admits listed sources (IP and IPv6 CIDR) and rejects everyone else.
    $settings->update(['allowed_ips' => '198.51.100.7, 2600:1700:56e0::/48']);
    expect(runApiAllowed('198.51.100.7'))->toBe(200);
    expect(runApiAllowed('2600:1700:56e0:1::42'))->toBe(200);
    expect(runApiAllowed('203.0.113.10'))->toBe(403);
    expect(runApiAllowed('2600:1700:ffff::1'))->toBe(403);

    // 0.0.0.0 stays the explicit allow-all escape.
    $settings->update(['allowed_ips' => '0.0.0.0']);
    expect(runApiAllowed('203.0.113.10'))->toBe(200);

    // An empty allowlist keeps the historical open behavior until the operator restricts it.
    $settings->update(['allowed_ips' => null]);
    expect(runApiAllowed('203.0.113.10'))->toBe(200);

    // The kill switch still wins over everything.
    $settings->update(['is_api_enabled' => false]);
    expect(runApiAllowed('198.51.100.7'))->toBe(403);
});

test('the api catch-all keeps the docs pointer on the instance docs url', function () {
    config()->set('constants.urls.docs', 'https://gridframes.app/llms.txt');

    $response = $this->getJson('/api/definitely-not-a-route');

    $response->assertStatus(404);
    expect($response->json('docs'))->toBe('https://gridframes.app/llms.txt');
});
