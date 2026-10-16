<?php

use App\Jobs\ServerCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Architecture note (commit 424dbd36ff "make sentinel mandatory on regular
// servers"): ServerManagerJob no longer dispatches CheckAndStartSentinelJob
// directly. The only path is ServerManagerJob → ServerCheckJob (queued) →
// ServerCheckJob::handle() → CheckAndStartSentinelJob. With Queue::fake()
// the inner job can never be pushed, so this family pins the manager-level
// contract instead: ServerCheckJob dispatch is gated by sentinelOutOfSync
// (sentinel_updated_at vs waitBeforeDoingSshCheck) at the manager's own
// cadence, independent of instance update_check_frequency.

beforeEach(function () {
    Queue::fake();
    Cache::flush(); // shouldRunCronNow dedup keys persist across tests otherwise

    seedInstanceSettings();

    $user = User::factory()->create();
    $this->team = $user->teams()->first();

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->server->settings->update([
        'is_sentinel_enabled' => true,
        'server_timezone' => 'UTC',
    ]);

    $this->server->refresh();
});

afterEach(function () {
    Carbon::setTestNow(); // Reset frozen time
});

// sentinel_updated_at defaults to real now() (2026) at row creation, which
// is always "fresher" than a frozen-2025 executionTime — the out-of-sync
// path would never trigger. Backdate AFTER freezing time.
function backdateSentinel(Server $server): void
{
    $server->forceFill(['sentinel_updated_at' => Carbon::now()->subDay()])->save();
}

it('dispatches server check when sentinel is out of sync, regardless of instance update_check_frequency', function () {
    InstanceSettings::first()->update([
        'update_check_frequency' => '0 0 1 1 *', // Yearly
        'instance_timezone' => 'UTC',
    ]);

    Carbon::setTestNow('2025-06-15 14:00:00');
    backdateSentinel($this->server);

    (new ServerManagerJob)->handle();

    // Self-hosted cadence is '* * * * *' (every minute), NOT the instance
    // update_check_frequency — the independence this family protects.
    Queue::assertPushed(ServerCheckJob::class, function ($job) {
        return $job->server->id === $this->server->id;
    });
});

it('does not dispatch server check when sentinel is in sync', function () {
    InstanceSettings::first()->update([
        'update_check_frequency' => '0 * * * *',
        'instance_timezone' => 'UTC',
    ]);

    Carbon::setTestNow('2025-06-15 14:00:00');
    // Leave sentinel_updated_at at its fresh default: sentinel is in sync,
    // so the manager must not enqueue any server check.
    (new ServerManagerJob)->handle();

    Queue::assertNotPushed(ServerCheckJob::class);
});

it('dispatches server check at every hour mark throughout the day', function () {
    InstanceSettings::first()->update([
        'update_check_frequency' => '0 0 1 1 *', // Yearly
        'instance_timezone' => 'UTC',
    ]);

    foreach ([0, 6, 12, 18, 23] as $hour) {
        Queue::fake();
        Cache::flush(); // fresh dedup window per iteration

        Carbon::setTestNow("2025-06-15 {$hour}:00:00");
        backdateSentinel($this->server);

        (new ServerManagerJob)->handle();

        Queue::assertPushed(ServerCheckJob::class, function ($job) {
            return $job->server->id === $this->server->id;
        }, "Failed to dispatch server check at hour {$hour}");
    }
});

it('dispatches server check regardless of server timezone', function () {
    $this->server->settings->update([
        'server_timezone' => 'America/New_York',
    ]);

    InstanceSettings::first()->update([
        'update_check_frequency' => '0 * * * *',
        'instance_timezone' => 'UTC',
    ]);

    // 17:00 UTC = 12:00 EST; with per-minute cadence any minute is due.
    Carbon::setTestNow('2025-01-15 17:00:00');
    backdateSentinel($this->server);

    (new ServerManagerJob)->handle();

    Queue::assertPushed(ServerCheckJob::class, function ($job) {
        return $job->server->id === $this->server->id;
    });
});

it('does not dedupe a second dispatch once a new minute is due', function () {
    InstanceSettings::first()->update([
        'instance_timezone' => 'UTC',
    ]);

    Carbon::setTestNow('2025-06-15 14:00:00');
    backdateSentinel($this->server);
    (new ServerManagerJob)->handle();
    Queue::assertPushed(ServerCheckJob::class);

    // Same minute again: the cron dedup cache suppresses a re-dispatch.
    Queue::fake();
    (new ServerManagerJob)->handle();
    Queue::assertNotPushed(ServerCheckJob::class);
});

it('handles multiple servers with different sync states', function () {
    $server2 = Server::factory()->create(['team_id' => $this->team->id]);
    $server3 = Server::factory()->create(['team_id' => $this->team->id]);

    InstanceSettings::first()->update([
        'instance_timezone' => 'UTC',
    ]);

    Carbon::setTestNow('2025-06-15 14:00:00');
    backdateSentinel($this->server);
    backdateSentinel($server3);
    // $server2 keeps its fresh sentinel_updated_at → in sync, no check.

    (new ServerManagerJob)->handle();

    Queue::assertPushed(ServerCheckJob::class, 2);

    Queue::assertPushed(ServerCheckJob::class, function ($job) {
        return $job->server->id === $this->server->id;
    });
    Queue::assertPushed(ServerCheckJob::class, function ($job) use ($server3) {
        return $job->server->id === $server3->id;
    });
});
