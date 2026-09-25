<?php

use App\Jobs\Machines\SampleMachineUsageJob;
use App\Models\InstanceSettings;
use App\Models\Machine;
use App\Models\MachineUsageSample;
use App\Models\Team;
use App\Services\Machines\IncusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const INCUS_SAMPLE = 'https://incus.test:8443';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-05-01 06:00:00'));
    Once::flush();
    config()->set('machines.incus', [
        'url' => INCUS_SAMPLE,
        'client_cert' => '/secrets/incus-client.crt',
        'client_key' => '/secrets/incus-client.key',
        'server_cert' => '/secrets/incus-server.crt',
        'pool' => 'gf-pool',
        'image' => 'gf-ubuntu-24.04',
    ]);
    $this->team = Team::factory()->create();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0, 'fqdn' => 'https://gridframes.test']));
});

function aRunningMachine(Team $team, array $attributes = []): Machine
{
    return Machine::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'team_id' => $team->id,
        'name' => 'web-1',
        'size_key' => 'm-1',
        'vcpu' => 1,
        'memory_mb' => 1024,
        'disk_gb' => 10,
        'incus_project' => "team-{$team->id}",
        'incus_name' => 'web-1',
        'state' => 'running',
    ], $attributes));
}

function aRunningStateAnswer(): array
{
    return ['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => [
        'status' => 'Running',
        'status_code' => 103,
        'memory' => ['usage' => 734003200], // 700 MiB exactly
        'disk' => ['root' => ['usage' => 5368709120]], // 5 GiB exactly
        'network' => [
            'lo' => ['counters' => ['bytes_received' => 1, 'bytes_sent' => null]],
            'eth0' => ['counters' => ['bytes_received' => 10, 'bytes_sent' => 1500000, 'packets_sent' => 900]],
        ],
    ]];
}

test('a running machine is read and its readings stored in MiB with the cumulative counter', function () {
    $machine = aRunningMachine($this->team);
    Http::fake([INCUS_SAMPLE.'/1.0/instances/*' => Http::response(aRunningStateAnswer())]);

    (new SampleMachineUsageJob)->handle(app(IncusClient::class));

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && $r->url() === INCUS_SAMPLE.'/1.0/instances/web-1/state');
    $sample = MachineUsageSample::where('machine_id', $machine->id)->first();
    expect((int) round($sample->sampled_at->diffInSeconds(now())))->toBeLessThan(5)
        ->and($sample->memory_mb)->toBe(700)
        ->and($sample->disk_mb)->toBe(5120)
        // The cumulative transmit counter across the machine's interfaces.
        ->and($sample->tx_bytes_total)->toBe(1500000)
        ->and($sample->isFailure())->toBeFalse();
});

test('machines that are not running are not read', function () {
    aRunningMachine($this->team, ['state' => 'stopped', 'stopped_reason' => 'customer', 'stopped_at' => now()]);
    Http::preventStrayRequests();

    (new SampleMachineUsageJob)->handle(app(IncusClient::class));

    Http::assertNothingSent();
    expect(MachineUsageSample::count())->toBe(0);
});

test('a host that cannot be reached stores a transport failure, never a zero', function () {
    $machine = aRunningMachine($this->team);
    Http::fake([INCUS_SAMPLE.'/1.0/instances/*' => Http::response(['type' => 'error', 'error' => 'Instance not found', 'error_code' => 404], 404)]);

    (new SampleMachineUsageJob)->handle(app(IncusClient::class));

    $sample = MachineUsageSample::where('machine_id', $machine->id)->first();
    expect($sample->isFailure())->toBeTrue()
        ->and($sample->failure_kind)->toBe('missing')
        ->and($sample->failure_detail)->toContain('no longer on the host')
        ->and($sample->memory_mb)->toBeNull()
        ->and($sample->disk_mb)->toBeNull()
        ->and($sample->tx_bytes_total)->toBeNull();
});

test("every exception kind is mapped into the sample's failure kind", function (array $answer, string $kind) {
    $machine = aRunningMachine($this->team);
    Http::fake([INCUS_SAMPLE.'/1.0/instances/*' => Http::response($answer, 500)]);

    (new SampleMachineUsageJob)->handle(app(IncusClient::class));

    expect(MachineUsageSample::where('machine_id', $machine->id)->first()->failure_kind)->toBe($kind);
})->with([
    'a host refusal is a malformed answer' => [['type' => 'error', 'error' => 'too busy', 'error_code' => 500], 'malformed'],
]);

test('a host that answers without readings stores a malformed failure, not zeros', function () {
    $machine = aRunningMachine($this->team);
    Http::fake([INCUS_SAMPLE.'/1.0/instances/*' => Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => [
        'status' => 'Running',
        'status_code' => 103,
    ]])]);

    (new SampleMachineUsageJob)->handle(app(IncusClient::class));

    $sample = MachineUsageSample::where('machine_id', $machine->id)->first();
    expect($sample->failure_kind)->toBe('malformed')
        ->and($sample->failure_detail)->toContain('memory')
        ->and($sample->memory_mb)->toBeNull()
        ->and($sample->tx_bytes_total)->toBeNull();
});

test('a run takes at most 1,000 machines and says in its log that it was partial', function () {
    $rows = [];
    for ($i = 1; $i <= SampleMachineUsageJob::BATCH + 1; $i++) {
        $rows[] = [
            'uuid' => (string) Str::uuid(),
            'team_id' => $this->team->id,
            'name' => 'web-'.$i,
            'size_key' => 'm-1',
            'vcpu' => 1,
            'memory_mb' => 1024,
            'disk_gb' => 10,
            'incus_project' => 'team-'.$this->team->id,
            'incus_name' => 'web-'.$i,
            'state' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    Machine::insert($rows);
    Http::fake([INCUS_SAMPLE.'/1.0/instances/*' => Http::response(aRunningStateAnswer())]);
    $warnings = [];
    Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$warnings) {
        $warnings[] = ['message' => $message, 'context' => $context];
    });

    (new SampleMachineUsageJob)->handle(app(IncusClient::class));

    expect(MachineUsageSample::count())->toBe(SampleMachineUsageJob::BATCH);
    $partial = collect($warnings)->first(fn ($logged) => str_contains($logged['message'], 'partial'));
    expect($partial)->not->toBeNull()
        ->and($partial['context']['pending'])->toBe(SampleMachineUsageJob::BATCH + 1);
});
