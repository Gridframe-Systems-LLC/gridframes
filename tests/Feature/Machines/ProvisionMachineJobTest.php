<?php

use App\Enums\ProxyTypes;
use App\Jobs\Machines\ProvisionMachineJob;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\Machine;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Services\Machines\IncusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

// TestCase is bound to Feature tests in tests/Pest.php; this file adds the database trait.
uses(RefreshDatabase::class);

const INCUS_PROVISION = 'https://incus.test:8443';

beforeEach(function () {
    config()->set('machines.enabled', true);
    config()->set('machines.incus', [
        'url' => INCUS_PROVISION,
        'client_cert' => '/secrets/incus-client.crt',
        'client_key' => '/secrets/incus-client.key',
        'server_cert' => '/secrets/incus-server.crt',
        'pool' => 'gf-pool',
        'image' => 'gf-ubuntu-24.04',
    ]);
    config()->set('machines.sizes', [
        'm-1' => ['vcpu' => 1, 'memory_mb' => 1024, 'disk_gb' => 10],
    ]);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::createAndStore([
        'name' => 'GridFrames machine web-1',
        'description' => 'Created by GridFrames',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $this->team->id,
    ]);
    $this->machine = aCreatingMachine($this->team, 'web-1');
});

function aCreatingMachine(Team $team, string $name): Machine
{
    return Machine::create([
        'uuid' => Str::uuid()->toString(),
        'team_id' => $team->id,
        'name' => $name,
        'size_key' => 'm-1',
        'vcpu' => 1,
        'memory_mb' => 1024,
        'disk_gb' => 10,
        'incus_project' => 'default',
        'incus_name' => 'gf-'.$team->id.'-'.$name,
        'state' => 'creating',
    ]);
}

function runProvisioning(Machine $machine, PrivateKey $privateKey): void
{
    (new ProvisionMachineJob($machine, $privateKey->id))->handle(new IncusClient);
}

/**
 * The Incus API, faked. `answers` maps a step name to the failure answer that step should give;
 * with no answer for a step the call succeeds.
 */
function fakeIncus(array $answers = []): void
{
    Http::fake(function (Request $request) use ($answers) {
        $method = $request->method();
        $url = $request->url();
        $step = null;

        if ($method === 'GET' && str_contains($url, '/1.0/networks/')) {
            $step = 'network-lookup';
        } elseif ($method === 'POST' && str_contains($url, '/1.0/networks')) {
            $step = 'network-create';
        } elseif ($method === 'POST' && $url === INCUS_PROVISION.'/1.0/instances') {
            $step = 'instance-create';
        } elseif ($method === 'PUT' && str_contains($url, '/instances/') && str_contains($url, '/state')) {
            $step = 'instance-start';
        } elseif ($method === 'GET' && str_contains($url, '/instances/') && str_contains($url, '/state')) {
            $step = 'instance-state';
        } elseif ($method === 'DELETE' && str_contains($url, '/instances/')) {
            $step = 'instance-delete';
        } elseif ($method === 'GET' && str_contains($url, '/operations/') && str_contains($url, '/wait')) {
            $step = 'operation-wait';
        }

        $answer = $answers[$step] ?? null;
        if ($answer !== null) {
            return $answer;
        }

        return match ($step) {
            'network-lookup' => Http::response(['error' => 'not found'], 404),
            'network-create', 'instance-create', 'instance-start', 'instance-delete' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-'.$step,
            ], 202),
            'instance-state' => incusStateAnswer(),
            default => incusWaitAnswer(),
        };
    });
}

function incusWaitAnswer(): object
{
    return Http::response([
        'type' => 'sync',
        'status' => 'Success',
        'status_code' => 200,
        'metadata' => ['status' => 'Done', 'status_code' => 200, 'err' => null],
    ]);
}

function incusStateAnswer(): object
{
    return Http::response([
        'type' => 'sync',
        'status' => 'Success',
        'status_code' => 200,
        'metadata' => [
            'status' => 'Running',
            'memory' => ['usage' => 268435456],
            'disk' => ['root' => ['usage' => 1073741824]],
            'network' => [
                'lo' => [
                    'addresses' => [['family' => 'inet', 'address' => '127.0.0.1', 'scope' => 'local']],
                    'counters' => ['bytes_received' => 0, 'bytes_sent' => 0, 'packets_received' => 0, 'packets_sent' => 0],
                ],
                'eth0' => [
                    'addresses' => [
                        ['family' => 'inet', 'address' => '10.150.0.12', 'scope' => 'global'],
                        ['family' => 'inet6', 'address' => 'fe80::1111', 'scope' => 'link'],
                        ['family' => 'inet6', 'address' => '2001:db8::1', 'scope' => 'global'],
                    ],
                    'counters' => ['bytes_received' => 100, 'bytes_sent' => 200, 'packets_received' => 2, 'packets_sent' => 3],
                ],
            ],
        ],
    ]);
}

it('creates the server exactly like ByHetzner does and sets the machine running', function () {
    Queue::fake();
    fakeIncus();

    runProvisioning($this->machine, $this->privateKey);

    $machine = $this->machine->fresh();
    expect($machine->state)->toBe('running')
        ->and($machine->ipv4_private)->toBe('10.150.0.12')
        ->and($machine->ipv6_public)->toBe('2001:db8::1')
        ->and($machine->server_id)->not->toBeNull()
        ->and($machine->failure)->toBeNull();

    $server = $machine->server;
    expect($server->name)->toBe($machine->name)
        ->and($server->ip)->toBe('10.150.0.12')
        ->and($server->port)->toBe(22)
        ->and($server->user)->toBe('root')
        ->and($server->team_id)->toBe($this->team->id)
        ->and($server->private_key_id)->toBe($this->privateKey->id)
        ->and($server->description)->toBe('GridFrames machine (m-1)')
        ->and($server->proxy->get('type'))->toBe(ProxyTypes::TRAEFIK->value)
        ->and($server->proxy->get('status'))->toBe('exited');

    Queue::assertPushed(ValidateAndInstallServerJob::class, fn (ValidateAndInstallServerJob $job) => $job->server->is($server));

    Http::assertSent(function (Request $request) use ($machine) {
        return $request->method() === 'POST'
            && $request->url() === INCUS_PROVISION.'/1.0/instances'
            && $request['name'] === $machine->incus_name
            && str_contains($request['config']['cloud-init.user-data'], 'hostname: web-1')
            && str_contains($request['config']['cloud-init.user-data'], $this->privateKey->getPublicKey());
    });
});

it('puts the customer key on the machine when one was given', function () {
    Queue::fake();
    fakeIncus();

    (new ProvisionMachineJob($this->machine, $this->privateKey->id, 'ssh-ed25519 AAAAC3Nza customer@example'))
        ->handle(new IncusClient);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/1.0/instances')
            && str_contains($request['config']['cloud-init.user-data'], 'ssh-ed25519 AAAAC3Nza customer@example');
    });
});

it('marks the machine failed with the reason and deletes nothing when the instance cannot be created', function () {
    fakeIncus([
        'instance-create' => Http::response(['error' => 'No space left on pool gf-pool'], 400),
    ]);

    runProvisioning($this->machine, $this->privateKey);

    $machine = $this->machine->fresh();
    expect($machine->state)->toBe('failed')
        ->and($machine->failure)->toContain('No space left on pool gf-pool')
        ->and($machine->server_id)->toBeNull();

    expect(Machine::query()->where('id', $this->machine->id)->where('state', 'failed')->exists())->toBeTrue();
    $this->assertDatabaseMissing('servers', ['team_id' => $this->team->id]);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});

it('deletes the instance when the start fails, leaving nothing half-made', function () {
    fakeIncus([
        'instance-start' => Http::response(['error' => 'Instance is not ready to start'], 400),
    ]);

    runProvisioning($this->machine, $this->privateKey);

    $machine = $this->machine->fresh();
    expect($machine->state)->toBe('failed')
        ->and($machine->failure)->toContain('Instance is not ready to start')
        ->and($machine->server_id)->toBeNull();

    Http::assertSent(function (Request $request) use ($machine) {
        return $request->method() === 'DELETE'
            && $request->url() === INCUS_PROVISION.'/1.0/instances/'.$machine->incus_name;
    });
    $this->assertDatabaseMissing('servers', ['team_id' => $this->team->id]);
});
