<?php

use App\Jobs\Machines\ChargeMonthlyOverageJob;
use App\Models\InstanceSettings;
use App\Models\Machine;
use App\Models\MachineOverageCharge;
use App\Models\MachineUsageSample;
use App\Models\Subscription;
use App\Models\Team;
use App\Services\GatewayCredits;
use App\Services\Machines\StripeMeters;
use App\Services\Machines\UsageCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Stripe\Collection;
use Stripe\Service\Billing\BillingServiceFactory;
use Stripe\Service\Billing\MeterEventService;
use Stripe\Service\Billing\MeterService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

const GATEWAY = 'https://gateway.test';

const APRIL_START = '2026-04-01 00:00:00';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-05-01 06:00:00'));
    Once::flush();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0, 'fqdn' => 'https://gridframes.test']));
    config()->set('subscription.credits', ['gateway_url' => GATEWAY, 'token' => str_repeat('p', 48), 'grace_days' => 3]);
    config()->set('subscription.stripe_api_key', 'sk_test_fake');
    config()->set('machines.stripe.meter_prefix', 'gridframes_machine');
    config()->set('subscription.plans', [
        'agent_1gb' => [
            'name' => 'Agent 1 GB', 'price_id' => null, 'price_label' => '', 'price_cents' => 600,
            'servers' => 1, 'tagline' => 't', 'machine' => '', 'lookup_key' => 'xlaunch_deploy_agent_1gb',
            'pool_memory_mb' => 1024, 'pool_disk_gb' => 10, 'pool_transfer_gb' => 50, 'per_member' => false,
            'overage_memory_cents_per_gb_month' => 400, 'overage_disk_cents_per_gb_month' => 8, 'overage_transfer_cents_per_gb' => 5,
        ],
    ]);
    $this->team = Team::factory()->create();
});

/**
 * A 2 GB machine over a 1 GB pool, running through the whole of April, transferring 1 GB an hour.
 * Its overage is 1 GB-month of memory (400c), 10 GB-months of disk (80c) and 669 GB (3345c).
 */
function anOverageMachine(Team $team, array $attributes = []): Machine
{
    $machine = Machine::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'team_id' => $team->id,
        'name' => 'web-1',
        'size_key' => 'm-2',
        'vcpu' => 1,
        'memory_mb' => 2048,
        'disk_gb' => 20,
        'incus_project' => "team-{$team->id}",
        'incus_name' => "web-1-{$team->id}",
        'state' => 'running',
    ], $attributes));
    $machine->forceFill(['created_at' => Carbon::parse(APRIL_START)])->saveQuietly();

    $rows = [];
    $at = Carbon::parse(APRIL_START);
    for ($hour = 1; $hour <= 720; $hour++) {
        $rows[] = [
            'machine_id' => $machine->id,
            'sampled_at' => $at->copy()->addHours($hour - 1),
            'memory_mb' => 900,
            'disk_mb' => 8000,
            'tx_bytes_total' => $hour * UsageCalculator::GB_BYTES,
        ];
        if (count($rows) === 120) {
            MachineUsageSample::insert($rows);
            $rows = [];
        }
    }
    MachineUsageSample::insert($rows);

    return $machine;
}

function aCreditsSubscription(Team $team, array $attributes = []): Subscription
{
    return Subscription::create(array_merge([
        'team_id' => $team->id,
        'payment_provider' => 'credits',
        'plan_key' => 'agent_1gb',
        'gateway_customer_id' => 42,
        'paid_until' => now()->addDays(20),
    ], $attributes));
}

function machineGatewayAnswers(int $chargeStatus = 201, array $chargeBody = ['applied' => true, 'chargeId' => 7, 'balanceMicros' => 4_000_000]): void
{
    Http::fake([
        GATEWAY.'/service-api/charges' => Http::response($chargeBody, $chargeStatus),
    ]);
}

function chargeJob(): ChargeMonthlyOverageJob
{
    return new ChargeMonthlyOverageJob;
}

test('a month inside the pool is charged nothing at all', function () {
    Http::fake();
    $machine = anOverageMachine($this->team, ['memory_mb' => 512, 'disk_gb' => 10, 'size_key' => 'm-0.5']);
    MachineUsageSample::where('machine_id', $machine->id)->update(['tx_bytes_total' => 0]);
    aCreditsSubscription($this->team);

    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), app(StripeClient::class), app(StripeMeters::class));

    expect(MachineOverageCharge::count())->toBe(0);
    Http::assertNothingSent();
});

test('a credit-paid team is charged its overage once, under the month reference', function () {
    aCreditsSubscription($this->team);
    anOverageMachine($this->team);
    machineGatewayAnswers();

    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), app(StripeClient::class), app(StripeMeters::class));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/charges')
        && $r['customerId'] === 42
        && $r['amountCents'] === 3825
        && $r['chargeRef'] === "team-{$this->team->id}-overage-2026-04");
    $charge = MachineOverageCharge::first();
    expect($charge->status)->toBe('paid')
        ->and($charge->provider)->toBe('credits')
        ->and($charge->total_cents)->toBe(3825)
        ->and($charge->memory_cents)->toBe(400)
        ->and($charge->disk_cents)->toBe(80)
        ->and($charge->transfer_cents)->toBe(3345)
        ->and($charge->provider_reference)->toBe('7');

    // A re-run reads the paid row and asks for nothing.
    Http::fake();
    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), app(StripeClient::class), app(StripeMeters::class));
    Http::assertNothingSent();
    expect(MachineOverageCharge::count())->toBe(1);
});

test('a gateway outage leaves the row pending and the next run finishes it', function () {
    aCreditsSubscription($this->team);
    anOverageMachine($this->team);
    // Http::fake's stubs merge rather than replace, so one stub plays both sides of the outage.
    $gatewayIsUp = false;
    Http::fake([
        GATEWAY.'/service-api/charges' => function () use (&$gatewayIsUp) {
            if (! $gatewayIsUp) {
                throw new ConnectionException('cURL error 28: Connection timed out after 5000 milliseconds');
            }

            return Http::response(['applied' => true, 'chargeId' => 7, 'balanceMicros' => 4_000_000], 201);
        },
    ]);

    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), app(StripeClient::class), app(StripeMeters::class));

    expect(MachineOverageCharge::count())->toBe(1)
        ->and(MachineOverageCharge::first()->status)->toBe('pending');

    $gatewayIsUp = true;
    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), app(StripeClient::class), app(StripeMeters::class));

    expect(MachineOverageCharge::count())->toBe(1)
        ->and(MachineOverageCharge::first()->status)->toBe('paid')
        ->and(MachineOverageCharge::first()->provider_reference)->toBe('7');
});

test('a refused credit charge is a failed row a re-run never retries', function () {
    aCreditsSubscription($this->team);
    anOverageMachine($this->team);
    machineGatewayAnswers(402, ['error' => ['message' => 'no'], 'balanceMicros' => 0, 'requiredMicros' => 38250000]);

    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), app(StripeClient::class), app(StripeMeters::class));

    expect(MachineOverageCharge::count())->toBe(1)
        ->and(MachineOverageCharge::first()->status)->toBe('failed');

    machineGatewayAnswers();
    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), app(StripeClient::class), app(StripeMeters::class));
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/charges'));
    expect(MachineOverageCharge::count())->toBe(1);
});

test("a card-paid team's overage becomes meter events on the three meters", function () {
    aCreditsSubscription($this->team, ['payment_provider' => 'stripe', 'gateway_customer_id' => null, 'stripe_customer_id' => 'cus_overage', 'stripe_subscription_id' => 'sub_1', 'stripe_invoice_paid' => true]);
    anOverageMachine($this->team);

    $events = [];
    $meterEvents = Mockery::mock(MeterEventService::class);
    $meterEvents->shouldReceive('create')->times(3)->andReturnUsing(function (array $params) use (&$events) {
        $events[] = $params;

        return (object) ['id' => 'mevt_'.count($events)];
    });
    $billing = Mockery::mock(BillingServiceFactory::class);
    $billing->meterEvents = $meterEvents;
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->billing = $billing;
    app()->bind(StripeClient::class, fn () => $stripe);

    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), $stripe, app(StripeMeters::class));

    $ref = "team-{$this->team->id}-overage-2026-04";
    expect(collect($events)->pluck('event_name')->all())->toBe([
        'gridframes_machine_memory_gb_months',
        'gridframes_machine_disk_gb_months',
        'gridframes_machine_transfer_gb',
    ])->and(collect($events)->pluck('identifier')->all())->toBe([
        "{$ref}-memory",
        "{$ref}-disk",
        "{$ref}-transfer",
    ])->and(collect($events)->pluck('payload.value')->all())->toBe(['1', '10', '669'])
        ->and(collect($events)->pluck('payload.stripe_customer_id')->all())->each->toBe('cus_overage')
        // Backdated into the usage month, inside Stripe's 35-day window.
        ->and(Carbon::createFromTimestamp($events[0]['timestamp'])->isSameMonth(Carbon::parse(APRIL_START)))->toBeTrue();

    $charge = MachineOverageCharge::first();
    expect($charge->status)->toBe('paid')
        ->and($charge->provider)->toBe('stripe')
        ->and($charge->provider_reference)->toBe('mevt_1,mevt_2,mevt_3');

    // A re-run reads the paid row and posts nothing further.
    $meterEvents->shouldReceive('create')->never();
    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), $stripe, app(StripeMeters::class));
    expect(MachineOverageCharge::count())->toBe(1);
});

test('a team whose plan carries no overage rates is never charged', function () {
    config()->set('subscription.plans.agent_1gb.overage_memory_cents_per_gb_month', 0);
    config()->set('subscription.plans.agent_1gb.overage_disk_cents_per_gb_month', 0);
    config()->set('subscription.plans.agent_1gb.overage_transfer_cents_per_gb', 0);
    aCreditsSubscription($this->team);
    anOverageMachine($this->team);
    machineGatewayAnswers();

    chargeJob()->handle(app(UsageCalculator::class), app(GatewayCredits::class), app(StripeClient::class), app(StripeMeters::class));

    expect(MachineOverageCharge::count())->toBe(0);
    Http::assertNothingSent();
});

test('machines:stripe-meters creates the three meters once and reports them the second time', function () {
    $meters = [
        ['id' => 'mtr_memory', 'event_name' => 'gridframes_machine_memory_gb_months'],
        ['id' => 'mtr_disk', 'event_name' => 'gridframes_machine_disk_gb_months'],
        ['id' => 'mtr_transfer', 'event_name' => 'gridframes_machine_transfer_gb'],
    ];
    $created = [];
    $meterService = Mockery::mock(MeterService::class);
    $meterService->shouldReceive('all')->with(['limit' => 100])->twice()->andReturn(
        Collection::constructFrom(['data' => [], 'has_more' => false], null),
        Collection::constructFrom(['data' => $meters, 'has_more' => false], null),
    );
    $meterService->shouldReceive('create')->times(3)->andReturnUsing(function (array $params) use (&$created) {
        $created[] = $params['event_name'];

        return (object) ['id' => 'mtr_'.count($created)];
    });
    $billing = Mockery::mock(BillingServiceFactory::class);
    $billing->meters = $meterService;
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->billing = $billing;
    config()->set('subscription.stripe_api_key', 'sk_test_fake');
    app()->bind(StripeClient::class, fn () => $stripe);

    $this->artisan('machines:stripe-meters')->assertExitCode(0);
    expect($created)->toBe([
        'gridframes_machine_memory_gb_months',
        'gridframes_machine_disk_gb_months',
        'gridframes_machine_transfer_gb',
    ]);

    $created = [];
    $this->artisan('machines:stripe-meters')->assertExitCode(0);
    expect($created)->toBe([]);
});
