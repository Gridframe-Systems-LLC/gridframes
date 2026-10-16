<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function serverManagerSentinelServer(array $attributes = []): Server
{
    $team = Team::factory()->create();

    return Server::factory()->create(array_merge([
        'team_id' => $team->id,
        'name' => 'test-server',
        'ip' => '192.168.1.100',
        'sentinel_updated_at' => Carbon::now(),
    ], $attributes));
}

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2025-01-15 12:00:00');
    seedInstanceSettings(['instance_timezone' => 'UTC']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('does not dispatch CheckAndStartSentinelJob hourly anymore', function () {
    serverManagerSentinelServer();

    (new ServerManagerJob)->handle();

    // Hourly CheckAndStartSentinelJob dispatch was removed — ServerCheckJob handles it when Sentinel is out of sync
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('does not schedule periodic Sentinel restart checks', function () {
    $root = dirname(__DIR__, 2);
    $manager = file_get_contents($root.'/app/Jobs/ServerManagerJob.php');
    $diagnostics = file_get_contents($root.'/app/Console/Commands/ScheduledJobDiagnostics.php');

    expect($manager)->not->toContain('sentinel-restart:')
        ->and($diagnostics)->not->toContain('sentinel-restart:');
});

it('skips ServerConnectionCheckJob when sentinel is live', function () {
    serverManagerSentinelServer();

    (new ServerManagerJob)->handle();

    // Sentinel is healthy so SSH connection check is skipped
    Queue::assertNotPushed(ServerConnectionCheckJob::class);
});

it('dispatches ServerConnectionCheckJob when sentinel is not live', function () {
    $server = serverManagerSentinelServer(['sentinel_updated_at' => Carbon::now()->subHour()]);

    (new ServerManagerJob)->handle();

    // Sentinel is out of sync so SSH connection check is needed
    Queue::assertPushed(ServerConnectionCheckJob::class, fn (ServerConnectionCheckJob $job) => $job->server->id === $server->id);
});

it('dispatches ServerConnectionCheckJob when sentinel is not enabled', function () {
    $server = serverManagerSentinelServer(['sentinel_updated_at' => Carbon::now()->subHour()]);
    $server->settings()->update(['is_build_server' => true]);

    (new ServerManagerJob)->handle();

    // Sentinel is not enabled so SSH connection check must run
    Queue::assertPushed(ServerConnectionCheckJob::class, fn (ServerConnectionCheckJob $job) => $job->server->id === $server->id);
});
