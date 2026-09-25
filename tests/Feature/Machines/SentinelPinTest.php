<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('pins the sentinel agent to a pullable public image with a version fallback', function () {
    config()->set('constants.xlaunch.sentinel_image', 'docker.io/coollabsio/sentinel');
    config()->set('constants.xlaunch.sentinel_version', '1.0.1');

    // The versions CDN cannot answer; the pinned config version takes over.
    Http::fake(['*' => Http::response(null, 500)]);

    expect(get_latest_sentinel_version())->toBe('1.0.1')
        ->and(config('constants.xlaunch.sentinel_image'))->toBe('docker.io/coollabsio/sentinel');
});

it('prefers the version the CDN reports when it can answer', function () {
    config()->set('constants.xlaunch.versions_url', 'https://versions.test/xlaunch/versions.json');
    Http::fake([
        'versions.test/*' => Http::response(['xlaunch' => ['sentinel' => ['version' => '1.0.2']]]),
    ]);

    expect(get_latest_sentinel_version())->toBe('1.0.2');
});
