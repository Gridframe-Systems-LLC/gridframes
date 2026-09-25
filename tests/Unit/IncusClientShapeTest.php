<?php

use App\Exceptions\MachineException;
use App\Models\Team;
use App\Services\Machines\IncusClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

const INCUS_SHAPE = 'https://incus.test:8443';

beforeEach(function () {
    config()->set('machines.incus', [
        'url' => INCUS_SHAPE,
        'client_cert' => '/secrets/incus-client.crt',
        'client_key' => '/secrets/incus-client.key',
        'server_cert' => '/secrets/incus-server.crt',
        'pool' => 'gf-pool',
        'image' => 'gf-ubuntu-24.04',
    ]);
    config()->set('machines.sizes', [
        'm-0.5' => ['vcpu' => 1, 'memory_mb' => 512, 'disk_gb' => 10],
        'm-1' => ['vcpu' => 1, 'memory_mb' => 1024, 'disk_gb' => 10],
        'm-2' => ['vcpu' => 1, 'memory_mb' => 2048, 'disk_gb' => 20],
        'm-4' => ['vcpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 40],
        'm-8' => ['vcpu' => 4, 'memory_mb' => 8192, 'disk_gb' => 80],
    ]);
});

function aTeamForShapes(): Team
{
    return (new Team)->forceFill(['id' => 7]);
}

function anOperationAnswer(string $operation = '/1.0/operations/op-1'): object
{
    return Http::response(['type' => 'async', 'status' => 'Operation created', 'status_code' => 100, 'operation' => $operation], 202);
}

it('creates a virtual machine from the golden image, sized and seeded with cloud-init', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/instances*' => anOperationAnswer('/1.0/operations/create-1'),
    ]);

    $result = (new IncusClient)->createInstance('team-7', 'web-1', 'm-2', "#cloud-config\nhostname: web-1");

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === INCUS_SHAPE.'/1.0/instances?project=team-7'
            && $request->header('Accept') === ['application/json']
            && $request['name'] === 'web-1'
            && $request['type'] === 'virtual-machine'
            && $request['source'] === ['type' => 'image', 'alias' => 'gf-ubuntu-24.04']
            && $request['config']['limits.cpu'] === '1'
            && $request['config']['limits.memory'] === '2048MiB'
            && $request['config']['cloud-init.user-data'] === "#cloud-config\nhostname: web-1"
            && $request['devices']['root'] === ['type' => 'disk', 'pool' => 'gf-pool', 'path' => '/', 'size' => '20GiB'];
    });

    expect($result['operation'])->toBe('/1.0/operations/create-1');
});

it('changes the instance state through the state endpoint', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/instances/*' => anOperationAnswer('/1.0/operations/state-1'),
    ]);

    $client = new IncusClient;
    $results = [$client->start('team-7', 'web-1'), $client->stop('team-7', 'web-1'), $client->restart('team-7', 'web-1')];

    $requests = collect(Http::recorded())->pluck(0)->values();
    expect($requests->count())->toBe(3)
        ->and($requests->every(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === INCUS_SHAPE.'/1.0/instances/web-1/state?project=team-7'))->toBeTrue()
        ->and($requests->map(fn (Request $request) => $request['action'])->all())->toBe(['start', 'stop', 'restart'])
        ->and($requests->map(fn (Request $request) => $request['timeout'])->all())->toBe([30, 30, 30])
        ->and($requests->map(fn (Request $request) => $request['force'])->all())->toBe([false, false, false])
        ->and(collect($results)->pluck('operation')->all())->toBe(['/1.0/operations/state-1', '/1.0/operations/state-1', '/1.0/operations/state-1']);
});

it('reads the instance state with memory, disk, addresses and network counters', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/instances/*' => Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => [
            'status' => 'Running',
            'status_code' => 103,
            'memory' => ['usage' => 73248768, 'usage_peak' => 73785344],
            'disk' => ['root' => ['usage' => 502239232, 'total' => 21474836480]],
            'network' => [
                'lo' => ['addresses' => [['family' => 'inet', 'address' => '127.0.0.1', 'netmask' => '8', 'scope' => 'local']]],
                'eth0' => [
                    'addresses' => [
                        ['family' => 'inet', 'address' => '10.50.0.5', 'netmask' => '24', 'scope' => 'global'],
                        ['family' => 'inet6', 'address' => '2001:db8:1::16', 'netmask' => '64', 'scope' => 'global'],
                    ],
                    'counters' => ['bytes_received' => 192021, 'bytes_sent' => 10888579, 'packets_received' => 1626, 'packets_sent' => 1159],
                ],
            ],
        ]]),
    ]);

    $state = (new IncusClient)->state('team-7', 'web-1');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === INCUS_SHAPE.'/1.0/instances/web-1/state?project=team-7');

    expect($state['status'])->toBe('Running')
        ->and($state['memory_bytes'])->toBe(73248768)
        ->and($state['disk_bytes'])->toBe(502239232)
        ->and($state['addresses'])->toBe([
            ['interface' => 'lo', 'family' => 'inet', 'address' => '127.0.0.1', 'scope' => 'local'],
            ['interface' => 'eth0', 'family' => 'inet', 'address' => '10.50.0.5', 'scope' => 'global'],
            ['interface' => 'eth0', 'family' => 'inet6', 'address' => '2001:db8:1::16', 'scope' => 'global'],
        ])
        ->and($state['network'])->toBe([
            'lo' => ['bytes_received' => null, 'bytes_sent' => null, 'packets_received' => null, 'packets_sent' => null],
            'eth0' => ['bytes_received' => 192021, 'bytes_sent' => 10888579, 'packets_received' => 1626, 'packets_sent' => 1159],
        ]);
});

it('reports a machine with no readings yet as not measured, not as zero', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/instances/*' => Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => [
            'status' => 'Running',
            'status_code' => 103,
        ]]),
    ]);

    $state = (new IncusClient)->state('team-7', 'web-1');

    expect($state['memory_bytes'])->toBeNull()
        ->and($state['disk_bytes'])->toBeNull()
        ->and($state['addresses'])->toBe([])
        ->and($state['network'])->toBe([]);
});

it('takes a snapshot of an instance', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/instances/*' => anOperationAnswer('/1.0/operations/snapshot-1'),
    ]);

    $result = (new IncusClient)->snapshot('team-7', 'web-1', 'before-upgrade');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === INCUS_SHAPE.'/1.0/instances/web-1/snapshots?project=team-7'
        && $request['name'] === 'before-upgrade'
        && $request['stateful'] === false);

    expect($result['operation'])->toBe('/1.0/operations/snapshot-1');
});

it('restores an instance to one of its snapshots', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/instances/*' => anOperationAnswer('/1.0/operations/restore-1'),
    ]);

    $result = (new IncusClient)->restore('team-7', 'web-1', 'before-upgrade');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === INCUS_SHAPE.'/1.0/instances/web-1?project=team-7'
        && $request['restore'] === 'before-upgrade');

    expect($result['operation'])->toBe('/1.0/operations/restore-1');
});

it('deletes an instance as an operation the caller can wait for', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/instances/*' => anOperationAnswer('/1.0/operations/delete-1'),
    ]);

    $result = (new IncusClient)->delete('team-7', 'web-1');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === INCUS_SHAPE.'/1.0/instances/web-1?project=team-7');

    expect($result['operation'])->toBe('/1.0/operations/delete-1');
});

it('creates the team project and its bridge when the host has neither', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/projects/team-7' => Http::response(['type' => 'error', 'error' => 'Project not found', 'error_code' => 404], 404),
        INCUS_SHAPE.'/1.0/projects' => Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => []]),
        INCUS_SHAPE.'/1.0/networks/gf-team-7*' => Http::response(['type' => 'error', 'error' => 'Network not found', 'error_code' => 404], 404),
        INCUS_SHAPE.'/1.0/networks*' => Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => []]),
    ]);

    $result = (new IncusClient)->ensureProject(aTeamForShapes());

    $requests = collect(Http::recorded())->pluck(0)->values();
    expect($requests->count())->toBe(4)
        ->and($requests[0]->method())->toBe('GET')
        ->and($requests[0]->url())->toBe(INCUS_SHAPE.'/1.0/projects/team-7')
        ->and($requests[1]->method())->toBe('POST')
        ->and($requests[1]->url())->toBe(INCUS_SHAPE.'/1.0/projects')
        ->and($requests[1]['name'])->toBe('team-7')
        ->and($requests[1]['config'])->toBe(['features.networks' => 'true'])
        ->and($requests[2]->method())->toBe('GET')
        ->and($requests[2]->url())->toBe(INCUS_SHAPE.'/1.0/networks/gf-team-7?project=team-7')
        ->and($requests[3]->method())->toBe('POST')
        ->and($requests[3]->url())->toBe(INCUS_SHAPE.'/1.0/networks?project=team-7')
        ->and($requests[3]['name'])->toBe('gf-team-7')
        ->and($requests[3]['type'])->toBe('bridge')
        ->and($requests[3]['config'])->toBe(['ipv4.nat' => 'true'])
        ->and($result)->toBe(['project' => 'team-7', 'network' => 'gf-team-7']);
});

it('leaves the team project and its bridge alone when the host has them', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/projects/team-7' => Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => []]),
        INCUS_SHAPE.'/1.0/networks/gf-team-7*' => Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => []]),
    ]);
    Http::preventStrayRequests();

    $result = (new IncusClient)->ensureProject(aTeamForShapes());

    $requests = collect(Http::recorded())->pluck(0);
    expect($requests->count())->toBe(2)
        ->and($requests->every(fn (Request $request) => $request->method() === 'GET'))->toBeTrue()
        ->and($result)->toBe(['project' => 'team-7', 'network' => 'gf-team-7']);
});

it('waits for an operation to finish and reports its result', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/operations/op-1/wait*' => Http::response([
            'type' => 'sync', 'status' => 'Success', 'status_code' => 200,
            'metadata' => ['id' => 'op-1', 'status' => 'Success', 'status_code' => 200, 'err' => ''],
        ]),
    ]);

    $result = (new IncusClient)->waitForOperation('/1.0/operations/op-1', 30);

    // Each wait call stays under the client's own 10-second HTTP timeout, so a 30-second budget
    // polls in slices of 8 seconds.
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === INCUS_SHAPE.'/1.0/operations/op-1/wait?timeout=8');

    expect($result['status'])->toBe('Success')
        ->and($result['err'])->toBeNull();
});

it('polls an operation until it finishes within the budget', function () {
    $answers = [
        Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => ['status' => 'Running', 'status_code' => 103, 'err' => '']]),
        Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => ['status' => 'Success', 'status_code' => 200, 'err' => '']]),
    ];
    Http::fake(function () use (&$answers) {
        return array_shift($answers);
    });

    $result = (new IncusClient)->waitForOperation('/1.0/operations/op-1', 30);

    expect(collect(Http::recorded())->count())->toBe(2)
        ->and($result['status'])->toBe('Success');
});

it('reports a failed operation with its reason', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/operations/op-1/wait*' => Http::response([
            'type' => 'sync', 'status' => 'Success', 'status_code' => 200,
            'metadata' => ['status' => 'Failure', 'status_code' => 400, 'err' => 'Failed to start device'],
        ]),
    ]);

    expect(fn () => (new IncusClient)->waitForOperation('/1.0/operations/op-1', 30))
        ->toThrow(fn (MachineException $e) => expect($e->kind)->toBe(MachineException::REJECTED)
            ->and($e->getMessage())->toBe('The host could not finish the work: Failed to start device'));
});

it('reports an operation that never finishes as a timeout', function () {
    Http::fake(fn () => Http::response([
        'type' => 'sync', 'status' => 'Success', 'status_code' => 200,
        'metadata' => ['status' => 'Running', 'status_code' => 103, 'err' => ''],
    ]));

    expect(fn () => (new IncusClient)->waitForOperation('/1.0/operations/op-1', 1))
        ->toThrow(fn (MachineException $e) => expect($e->kind)->toBe(MachineException::TIMEOUT));
});

it('refuses to speak when a piece of the Incus connection is missing', function (string $key) {
    config()->set("machines.incus.{$key}", null);
    Http::preventStrayRequests();

    expect(fn () => (new IncusClient)->start('team-7', 'web-1'))
        ->toThrow(fn (MachineException $e) => expect($e->kind)->toBe(MachineException::UNCONFIGURED));
})->with([
    'blank url' => 'url',
    'blank client cert' => 'client_cert',
    'blank client key' => 'client_key',
    'blank pinned server cert' => 'server_cert',
]);

it('refuses a size the instance does not offer before asking the host', function () {
    Http::preventStrayRequests();

    expect(fn () => (new IncusClient)->createInstance('team-7', 'web-1', 'm-999', '#cloud-config'))
        ->toThrow(fn (MachineException $e) => expect($e->kind)->toBe(MachineException::UNCONFIGURED)
            ->and($e->getMessage())->toContain('m-999'));
});

it('reports an unreachable host as a transport failure', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to incus.test port 8443'));

    expect(fn () => (new IncusClient)->createInstance('team-7', 'web-1', 'm-2', '#cloud-config'))
        ->toThrow(fn (MachineException $e) => expect($e->kind)->toBe(MachineException::TRANSPORT));
});

it('reports a host that answers too late as a timeout', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out after 10000 milliseconds'));

    expect(fn () => (new IncusClient)->state('team-7', 'web-1'))
        ->toThrow(fn (MachineException $e) => expect($e->kind)->toBe(MachineException::TIMEOUT));
});

it('reports a machine the host does not know as not found', function () {
    Http::fake([
        INCUS_SHAPE.'/1.0/instances/*' => Http::response(['type' => 'error', 'error' => 'Instance not found', 'error_code' => 404], 404),
    ]);

    expect(fn () => (new IncusClient)->state('team-7', 'ghost'))
        ->toThrow(fn (MachineException $e) => expect($e->kind)->toBe(MachineException::NOT_FOUND)
            ->and($e->getMessage())->toContain('ghost'));
});

it('reports a host refusal with its reason', function (int $status) {
    Http::fake([
        INCUS_SHAPE.'/1.0/instances*' => Http::response(['type' => 'error', 'error' => 'Instance name already in use', 'error_code' => $status], $status),
    ]);

    expect(fn () => (new IncusClient)->createInstance('team-7', 'web-1', 'm-2', '#cloud-config'))
        ->toThrow(fn (MachineException $e) => expect($e->kind)->toBe(MachineException::REJECTED)
            ->and($e->getMessage())->toContain('Instance name already in use'));
})->with([
    '400 bad request' => 400,
    '409 conflict' => 409,
]);
