<?php

use App\Jobs\Machines\EnforceMachineLifecycleJob;
use App\Jobs\VerifyStripeSubscriptionStatusJob;
use App\Models\Machine;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TransactionalEmails\MachineLifecycleEmail;
use App\Services\Machines\IncusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

const INCUS_LIFECYCLE = 'https://incus.test:8443';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-05-01 06:00:00'));
    Once::flush();
    config()->set('constants.xlaunch.self_hosted', false);
    config()->set('machines.enabled', true);
    config()->set('machines.incus', [
        'url' => INCUS_LIFECYCLE,
        'client_cert' => '/secrets/incus-client.crt',
        'client_key' => '/secrets/incus-client.key',
        'server_cert' => '/secrets/incus-server.crt',
        'pool' => 'gf-pool',
        'image' => 'gf-ubuntu-24.04',
    ]);
    config()->set('machines.lifecycle', [
        'stop_after_grace_days' => 3,
        'delete_after_stop_days' => 14,
        'final_snapshot_keep_days' => 30,
    ]);
    config()->set('subscription.credits', ['gateway_url' => 'https://gateway.test', 'token' => str_repeat('p', 48), 'grace_days' => 3]);
    config()->set('subscription.plans', [
        'agent_1gb' => [
            'name' => 'Agent 1 GB', 'price_id' => null, 'price_label' => '', 'price_cents' => 600,
            'servers' => 1, 'tagline' => 't', 'machine' => '', 'lookup_key' => 'xlaunch_deploy_agent_1gb',
        ],
    ]);
    config()->set('subscription.stripe_api_key', 'sk_test_fake');

    Mail::fake();
    Notification::fake();
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
});

function aLifecycleMachine(Team $team, array $attributes = []): Machine
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

function aCreditsPlan(Team $team, array $attributes = []): Subscription
{
    return Subscription::create(array_merge([
        'team_id' => $team->id,
        'payment_provider' => 'credits',
        'plan_key' => 'agent_1gb',
        'gateway_customer_id' => 42,
        'paid_until' => now()->addDays(20),
    ], $attributes));
}

function lifecycleIncusFake(): void
{
    Http::fake([
        INCUS_LIFECYCLE.'/1.0/instances/*/state*' => Http::response(['type' => 'async', 'status' => 'Operation created', 'status_code' => 100, 'operation' => '/1.0/operations/state-1'], 202),
        INCUS_LIFECYCLE.'/1.0/instances/*/snapshots*' => Http::response(['type' => 'async', 'status' => 'Operation created', 'status_code' => 100, 'operation' => '/1.0/operations/snap-1'], 202),
        INCUS_LIFECYCLE.'/1.0/operations/*/wait*' => Http::response(['type' => 'sync', 'status' => 'Success', 'status_code' => 200, 'metadata' => ['status' => 'Success', 'status_code' => 200, 'err' => '']]),
        INCUS_LIFECYCLE.'/1.0/instances/*' => Http::response(['type' => 'async', 'status' => 'Operation created', 'status_code' => 100, 'operation' => '/1.0/operations/delete-1'], 202),
    ]);
}

function theLifecycleJob(?Team $team = null): EnforceMachineLifecycleJob
{
    return new EnforceMachineLifecycleJob($team);
}

test('a machine is stopped with an email once the grace days after a failed payment are out', function () {
    aCreditsPlan($this->team, ['paid_until' => now()->subDays(4)]);
    $machine = aLifecycleMachine($this->team);
    lifecycleIncusFake();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
        && $r->url() === INCUS_LIFECYCLE.'/1.0/instances/web-1/state'
        && $r['action'] === 'stop');
    $machine->refresh();
    expect($machine->state)->toBe('stopped')
        ->and($machine->stopped_reason)->toBe('payment')
        ->and($machine->stopped_at)->not->toBeNull()
        ->and($machine->delete_after)->toBeNull();
    Notification::assertSentTo($this->owner, MachineLifecycleEmail::class, fn (MachineLifecycleEmail $n) => str_contains($n->subject, 'stopped'));
});

test('a machine keeps running through the grace days after a failed payment', function () {
    aCreditsPlan($this->team, ['paid_until' => now()->subDays(2)]);
    $machine = aLifecycleMachine($this->team);
    Http::preventStrayRequests();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    expect($machine->fresh()->state)->toBe('running');
    Notification::assertNothingSent();
});

test('a machine stopped for payment starts again once the plan is paid', function () {
    aCreditsPlan($this->team, ['paid_until' => now()->addDays(20)]);
    $machine = aLifecycleMachine($this->team, ['state' => 'stopped', 'stopped_reason' => 'payment', 'stopped_at' => now()->subDays(2)]);
    lifecycleIncusFake();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/state') && $r['action'] === 'start');
    $machine->refresh();
    expect($machine->state)->toBe('running')
        ->and($machine->stopped_reason)->toBeNull()
        ->and($machine->stopped_at)->toBeNull();
});

test('a machine keeps waiting while its plan is paid, even after it was stopped by the customer', function () {
    aCreditsPlan($this->team);
    $machine = aLifecycleMachine($this->team, ['state' => 'stopped', 'stopped_reason' => 'customer', 'stopped_at' => now()->subDays(1)]);
    Http::preventStrayRequests();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    expect($machine->fresh()->state)->toBe('stopped')
        ->and($machine->fresh()->stopped_reason)->toBe('customer');
    Http::assertNothingSent();
});

test('a credits plan that was not renewed stops its machines at once and sets deletion 14 days out', function () {
    aCreditsPlan($this->team, ['paid_until' => now()->subDay(), 'stripe_cancel_at_period_end' => true]);
    $machine = aLifecycleMachine($this->team);
    lifecycleIncusFake();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    $machine->refresh();
    expect($machine->state)->toBe('stopped')
        ->and($machine->stopped_reason)->toBe('plan-ended')
        ->and($machine->stopped_at->diffInHours(now()))->toBeLessThan(2)
        ->and(abs((int) round($machine->delete_after->diffInSeconds($machine->stopped_at))))->toBe(14 * 86400);
});

test('a card plan that has ended stops its machines at once', function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_invoice_paid' => false]);
    $machine = aLifecycleMachine($this->team);
    lifecycleIncusFake();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    $machine->refresh();
    expect($machine->state)->toBe('stopped')
        ->and($machine->stopped_reason)->toBe('plan-ended');
});

test("a past-due card is still Stripe's own grace, so machines keep running", function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_subscription_id' => 'sub_1', 'stripe_customer_id' => 'cus_1', 'stripe_invoice_paid' => true, 'stripe_past_due' => true]);
    $machine = aLifecycleMachine($this->team);
    Http::preventStrayRequests();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    expect($machine->fresh()->state)->toBe('running');
    Http::assertNothingSent();
});

test('a trial is a plan in force and machines keep running', function () {
    aCreditsPlan($this->team, ['paid_until' => now()->addDays(10), 'trial_ends_at' => now()->addDays(10)]);
    $machine = aLifecycleMachine($this->team);
    Http::preventStrayRequests();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    expect($machine->fresh()->state)->toBe('running');
    Http::assertNothingSent();
});

test('a team with ended-plan machines is warned on day one, once', function () {
    aCreditsPlan($this->team);
    $machine = aLifecycleMachine($this->team, [
        'state' => 'stopped',
        'stopped_reason' => 'plan-ended',
        'stopped_at' => now()->subHours(25),
        'delete_after' => now()->addDays(13),
    ]);
    Http::preventStrayRequests();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));
    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    Http::assertNothingSent();
    Notification::assertSentTo($this->owner, MachineLifecycleEmail::class, fn (MachineLifecycleEmail $n) => str_contains($n->subject, 'deletion'));
    expect(Notification::sent($this->owner, MachineLifecycleEmail::class))->toHaveCount(1)
        ->and($machine->fresh()->state)->toBe('stopped');
});

test('a machine past its deletion day is snapshotted, then deleted, with the removal logged first', function () {
    aCreditsPlan($this->team);
    $machine = aLifecycleMachine($this->team, [
        'state' => 'stopped',
        'stopped_reason' => 'plan-ended',
        'stopped_at' => now()->subDays(15),
        'delete_after' => now()->subHour(),
    ]);
    lifecycleIncusFake();

    $logs = [];
    Log::shouldReceive('info')->andReturnUsing(function ($message, $context = []) use (&$logs) {
        $logs[] = ['message' => $message, 'context' => $context];
    });
    Log::shouldReceive('warning')->andReturnNull();

    theLifecycleJob()->handle(app(IncusClient::class));

    $requests = collect(Http::recorded())->pluck(0)->values();
    $snapshotAt = $requests->search(fn (Request $r) => str_contains($r->url(), '/snapshots'));
    $deleteAt = $requests->search(fn (Request $r) => $r->method() === 'DELETE');
    expect($snapshotAt)->not->toBeFalse()
        ->and($deleteAt)->not->toBeFalse()
        ->and($snapshotAt)->toBeLessThan($deleteAt)
        ->and($requests[$snapshotAt]['name'])->toStartWith('final-')
        ->and($requests[$snapshotAt]['expires_at'])->not->toBeNull();

    // Ground rule 6: the record of what is about to go names the machine, its instance and its server.
    $removal = collect($logs)->first(fn ($log) => str_contains($log['message'], 'This is what will be removed'));
    expect($removal)->not->toBeNull()
        ->and($removal['context']['machine'])->toBe('web-1')
        ->and($removal['context']['incus_instance'])->toBe('web-1')
        ->and($removal['context']['incus_project'])->toBe('team-'.$this->team->id)
        ->and($removal['context']['snapshot_taken'])->toStartWith('final-');

    expect(Machine::count())->toBe(0)
        ->and(Machine::withTrashed()->find($machine->id)->trashed())->toBeTrue()
        ->and(Machine::withTrashed()->find($machine->id)->state)->toBe('deleted');
});

test('a machine that is not yet due for deletion is left alone', function () {
    aCreditsPlan($this->team);
    $machine = aLifecycleMachine($this->team, [
        'state' => 'stopped',
        'stopped_reason' => 'plan-ended',
        'stopped_at' => now()->subDays(3),
        'delete_after' => now()->addDays(11),
    ]);
    Http::preventStrayRequests();

    theLifecycleJob($this->team)->handle(app(IncusClient::class));

    expect($machine->fresh()->state)->toBe('stopped')
        ->and(Machine::count())->toBe(1);
    Http::assertNothingSent();
});

test('a verified Stripe status that ends the plan nudges the machines when the team has any', function () {
    $subscription = Subscription::create(['team_id' => $this->team->id, 'stripe_subscription_id' => 'sub_verify_1', 'stripe_customer_id' => 'cus_verify_1', 'stripe_invoice_paid' => true]);
    aLifecycleMachine($this->team);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockSubscriptions = Mockery::mock(SubscriptionService::class);
    $mockStripe->subscriptions = $mockSubscriptions;
    $mockSubscriptions->shouldReceive('retrieve')->with('sub_verify_1')->andReturn((object) [
        'status' => 'unpaid',
        'cancel_at_period_end' => false,
    ]);
    app()->bind(StripeClient::class, fn () => $mockStripe);

    (new VerifyStripeSubscriptionStatusJob($subscription))->handle();

    Queue::assertPushed(EnforceMachineLifecycleJob::class, fn (EnforceMachineLifecycleJob $job) => $job->team?->id === $this->team->id);
});

test('a verified Stripe status dispatches nothing for a team without machines', function () {
    Subscription::create(['team_id' => $this->team->id, 'stripe_subscription_id' => 'sub_verify_2', 'stripe_customer_id' => 'cus_verify_2', 'stripe_invoice_paid' => true]);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockSubscriptions = Mockery::mock(SubscriptionService::class);
    $mockStripe->subscriptions = $mockSubscriptions;
    $mockSubscriptions->shouldReceive('retrieve')->with('sub_verify_2')->andReturn((object) [
        'status' => 'unpaid',
        'cancel_at_period_end' => false,
    ]);
    app()->bind(StripeClient::class, fn () => $mockStripe);

    (new VerifyStripeSubscriptionStatusJob(Subscription::where('team_id', $this->team->id)->first()))->handle();

    Queue::assertNothingPushed();
});
