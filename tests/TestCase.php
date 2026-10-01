<?php

namespace Tests;

use App\Models\Server;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

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
