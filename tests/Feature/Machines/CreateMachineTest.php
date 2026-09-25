<?php

use App\Actions\Machines\CreateMachine;
use App\Exceptions\MachineException;
use App\Jobs\Machines\ProvisionMachineJob;
use App\Models\Machine;
use App\Models\PrivateKey;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

// TestCase is bound to Feature tests in tests/Pest.php; this file adds the database trait.
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('machines.enabled', true);
    config()->set('machines.host', [
        'vcpu' => 16,
        'memory_mb' => 32768,
        'disk_gb' => 1000,
        'memory_overcommit' => 1.0,
    ]);
    config()->set('machines.sizes', [
        'm-1' => ['vcpu' => 1, 'memory_mb' => 1024, 'disk_gb' => 10],
        'm-2' => ['vcpu' => 1, 'memory_mb' => 2048, 'disk_gb' => 20],
        'm-8' => ['vcpu' => 4, 'memory_mb' => 8192, 'disk_gb' => 80],
    ]);
    config()->set('subscription.plans', ['personal' => aPersonalPlan()]);
    $this->team = Team::factory()->create();
    subscribeTeamForMachines($this->team);
});

function aPersonalPlan(array $overrides = []): array
{
    return array_merge([
        'name' => 'Personal',
        'price_cents' => 2000,
        'price_id' => 'price_test_personal',
        'pool_vcpu' => 2,
        'pool_memory_mb' => 8192,
        'pool_disk_gb' => 100,
        'pool_transfer_gb' => 200,
        'max_machines' => 50,
        'overage_memory_cents_per_gb_month' => 0,
        'overage_disk_cents_per_gb_month' => 0,
        'overage_transfer_cents_per_gb' => 0,
        'per_member' => false,
    ], $overrides);
}

function subscribeTeamForMachines(Team $team): Subscription
{
    return Subscription::create([
        'team_id' => $team->id,
        'plan_key' => 'personal',
        'payment_provider' => 'stripe',
        'stripe_invoice_paid' => true,
    ]);
}

function aLiveMachine(Team $team, string $name, string $sizeKey): Machine
{
    $size = config("machines.sizes.{$sizeKey}");

    return Machine::create([
        'uuid' => Str::uuid()->toString(),
        'team_id' => $team->id,
        'name' => $name,
        'size_key' => $sizeKey,
        'vcpu' => $size['vcpu'],
        'memory_mb' => $size['memory_mb'],
        'disk_gb' => $size['disk_gb'],
        'incus_project' => 'team-'.$team->id,
        'incus_name' => 'gf-'.$team->id.'-'.$name,
        'state' => 'running',
    ]);
}

function machineRefusal(callable $attempt): MachineException
{
    try {
        $attempt();
    } catch (MachineException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected CreateMachine to refuse, but it did not.');
}

it('creates a machine within the pool and queues its provisioning', function () {
    Queue::fake();

    $machine = (new CreateMachine)->handle($this->team, 'Web-1', 'm-2');

    expect($machine->state)->toBe('creating')
        ->and($machine->name)->toBe('web-1')
        ->and($machine->size_key)->toBe('m-2')
        ->and($machine->vcpu)->toBe(1)
        ->and($machine->memory_mb)->toBe(2048)
        ->and($machine->disk_gb)->toBe(20)
        ->and($machine->incus_project)->toBe('default')
        ->and($machine->incus_name)->toBe('gf-'.$this->team->id.'-web-1')
        ->and($machine->uuid)->not->toBeEmpty();

    expect(PrivateKey::where('team_id', $this->team->id)->where('name', 'GridFrames machine web-1')->exists())->toBeTrue();

    Queue::assertPushed(ProvisionMachineJob::class, function (ProvisionMachineJob $job) use ($machine) {
        return $job->machine->is($machine) && $job->privateKeyId !== null;
    });
});

it('lets a trialing team create a machine', function () {
    Queue::fake();
    $this->team->subscription->update(['stripe_invoice_paid' => false, 'trial_ends_at' => now()->addWeek()]);

    $machine = (new CreateMachine)->handle($this->team, 'trial-machine', 'm-1');

    expect($machine->state)->toBe('creating');
    Queue::assertPushed(ProvisionMachineJob::class);
});

it('refuses a team that is not subscribed or trialing', function () {
    $this->team->subscription->delete();
    // Drop the relation the delete left on the instance; the action reads the team as callers do.
    $this->team->unsetRelation('subscription');

    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'lonely', 'm-1'))->getMessage())->toContain('plan');
});

it('refuses when the team plan grants no pool', function () {
    config()->set('subscription.plans', ['personal' => aPersonalPlan(['pool_vcpu' => 0, 'pool_memory_mb' => 0, 'pool_disk_gb' => 0])]);

    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'no-pool', 'm-1'))->getMessage())->toContain('plan');
});

it('refuses over the pool naming the resource that does not fit', function () {
    config()->set('subscription.plans', ['personal' => aPersonalPlan(['pool_memory_mb' => 2048])]);
    aLiveMachine($this->team, 'first', 'm-2');

    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'second', 'm-1'))->getMessage())->toContain('memory');
});

it('refuses a machine beyond the plan machine count', function () {
    config()->set('subscription.plans', ['personal' => aPersonalPlan(['max_machines' => 1])]);
    aLiveMachine($this->team, 'only', 'm-1');

    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'second', 'm-1'))->getMessage())->toContain('machine');
});

it('refuses over the pool even when accepted while overage is off for the plan', function () {
    config()->set('subscription.plans', ['personal' => aPersonalPlan(['pool_memory_mb' => 2048])]);
    aLiveMachine($this->team, 'first', 'm-2');

    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'second', 'm-1', acceptOverage: true))->getMessage())->toContain('memory');
});

it('refuses over the pool when overage is on but the customer has not accepted', function () {
    config()->set('subscription.plans', ['personal' => aPersonalPlan([
        'pool_memory_mb' => 2048,
        'overage_memory_cents_per_gb_month' => 400,
        'overage_disk_cents_per_gb_month' => 8,
    ])]);
    aLiveMachine($this->team, 'first', 'm-2');

    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'second', 'm-1'))->getMessage())->toContain('memory');
});

it('accepts over the pool only when overage is on and the customer accepted', function () {
    Queue::fake();
    config()->set('subscription.plans', ['personal' => aPersonalPlan([
        'pool_memory_mb' => 2048,
        'overage_memory_cents_per_gb_month' => 400,
        'overage_disk_cents_per_gb_month' => 8,
    ])]);
    aLiveMachine($this->team, 'first', 'm-2');

    $machine = (new CreateMachine)->handle($this->team, 'second', 'm-1', acceptOverage: true);

    expect($machine->state)->toBe('creating');
    Queue::assertPushed(ProvisionMachineJob::class);
});

it('refuses cleanly when the host is full', function () {
    config()->set('machines.host', ['vcpu' => 16, 'memory_mb' => 1024, 'disk_gb' => 1000, 'memory_overcommit' => 1.0]);

    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'toobig', 'm-2'))->getMessage())
        ->toBe('GridFrames is at capacity; try a smaller size or contact support');
});

it('sells overcommitted memory but no more than the host really has', function () {
    config()->set('machines.host', ['vcpu' => 16, 'memory_mb' => 1024, 'disk_gb' => 1000, 'memory_overcommit' => 2.0]);

    $fits = (new CreateMachine)->handle($this->team, 'fits', 'm-2');
    expect($fits->state)->toBe('creating');

    // Two m-2 machines allocate 4096 MB against a 2048 MB overcommitted host: the third must not start.
    aLiveMachine($this->team, 'second', 'm-2');
    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'third', 'm-2'))->getMessage())
        ->toBe('GridFrames is at capacity; try a smaller size or contact support');
});

it('refuses when machines are turned off', function () {
    config()->set('machines.enabled', false);

    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'web-1', 'm-1'))->getMessage())->toContain('turned off');
});

it('does not count a failed machine against the pool or the host', function () {
    Queue::fake();
    // A failed m-8 left its resources unheld: its instance was deleted when provisioning failed.
    aLiveMachine($this->team, 'ghost', 'm-8')->update(['state' => 'failed']);

    $machine = (new CreateMachine)->handle($this->team, 'fresh', 'm-1');

    expect($machine->state)->toBe('creating');
    Queue::assertPushed(ProvisionMachineJob::class);
});

it('refuses a name that is not a DNS label or is already taken', function () {
    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'Bad_Name', 'm-1'))->getMessage())->toContain('name');

    (new CreateMachine)->handle($this->team, 'dup', 'm-1');
    expect(machineRefusal(fn () => (new CreateMachine)->handle($this->team, 'dup', 'm-1'))->getMessage())->toContain('named');
});
