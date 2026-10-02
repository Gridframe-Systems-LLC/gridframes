<?php

namespace Tests;

use App\Models\Server;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // CI never runs `npm run build` and the repo never commits
        // public/build, so any test that renders a page with @vite dies with
        // ViteManifestNotFoundException (the 477-failure family on CI). Dev
        // machines don't see it only because public/hot masks the missing
        // manifest. Rendering tests assert on page content, never on Vite
        // asset tags, so swap Vite for the framework's no-op — the same
        // $this->withoutVite() many test files already call per-test.
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        // Server::findCached() is a static identity map that survives
        // RefreshDatabase's rollback; a stale entry from a prior test would
        // leak a wrong-named Server into the next test (the sqlite id
        // sequence restarts at 1 after rollback).
        Server::flushIdentityMap();

        parent::tearDown();
    }
}
