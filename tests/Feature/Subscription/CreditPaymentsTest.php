<?php

use App\Actions\Subscription\ChargeCreditsPeriod;
use App\Actions\Subscription\SubscribeWithCredits;
use App\Exceptions\CreditsPaymentException;
use App\Jobs\RenewCreditSubscriptionsJob;
use App\Jobs\StripeProcessJob;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

const GATEWAY = 'https://gateway.test';
const AGENT_KEY = 'xl-agent-key-000000000000000001';

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0, 'fqdn' => 'https://gridframes.test']));
    Once::flush();
    config()->set('constants.xlaunch.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.credits', ['gateway_url' => GATEWAY, 'token' => str_repeat('p', 48), 'grace_days' => 3]);
    config()->set('subscription.trial_days', 0);
    config()->set('subscription.plans', [
        'agent_1gb' => ['name' => 'Agent 1 GB', 'price_id' => null, 'price_label' => '', 'price_cents' => 600, 'servers' => 1, 'tagline' => 't', 'machine' => '', 'lookup_key' => 'xlaunch_deploy_agent_1gb'],
        'team' => ['name' => 'Team', 'price_id' => 'price_team', 'price_label' => '$25', 'price_cents' => 2500, 'servers' => 10, 'tagline' => 't', 'machine' => '', 'lookup_key' => 'xlaunch_deploy_team'],
    ]);
    $this->team = Team::factory()->create();
});

function gatewayAnswers(int $chargeStatus = 201, array $chargeBody = ['applied' => true, 'chargeId' => 7, 'balanceMicros' => 4_000_000]): void
{
    Http::fake([
        GATEWAY.'/service-api/accounts/resolve-key' => Http::response(['customerId' => 42, 'email' => 'agent@example.com', 'balanceMicros' => 10_000_000]),
        GATEWAY.'/service-api/charges' => Http::response($chargeBody, $chargeStatus),
    ]);
}

test('a plan paid from credits is on sale without a Stripe price, and shows its price from the cents', function () {
    $plan = plansOnSale()->get('agent_1gb');

    expect($plan['by_credits'])->toBeTrue()
        ->and($plan['by_card'])->toBeFalse()
        ->and($plan['price_label'])->toBe('$6');
});

test('without a gateway connection no plan can be paid from credits', function () {
    config()->set('subscription.credits.token', null);

    expect(plansOnSale()->has('agent_1gb'))->toBeFalse()
        ->and(plansOnSale()->get('team')['by_credits'])->toBeFalse();
});

test('subscribing charges the first month once, under the team, tier and day', function () {
    gatewayAnswers();
    $this->travelTo(now()->setDate(2026, 9, 24)->setTime(10, 0));

    $subscription = app(SubscribeWithCredits::class)->execute($this->team, 'agent_1gb', AGENT_KEY);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/charges')
        && $r['customerId'] === 42 && $r['amountCents'] === 600
        && $r['chargeRef'] === "team-{$this->team->id}-agent_1gb-20260924"
        && $r->hasHeader('Authorization', 'Bearer '.str_repeat('p', 48)));
    expect($subscription->payment_provider)->toBe('credits')
        ->and($subscription->gateway_customer_id)->toBe(42)
        ->and($subscription->paid_until->toDateString())->toBe('2026-10-24')
        ->and($this->team->fresh()->custom_server_limit)->toBe(1)
        ->and(SubscriptionCharge::where('status', 'paid')->count())->toBe(1);

    Once::flush();
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'owner']);
    $this->actingAs($member);
    session(['currentTeam' => ['id' => $this->team->id]]);
    expect(isSubscriptionActive())->toBeTrue();
});

test('the key is used to find the account and is never stored', function () {
    gatewayAnswers();

    app(SubscribeWithCredits::class)->execute($this->team, 'agent_1gb', AGENT_KEY);

    $stored = json_encode([Subscription::first()->toArray(), SubscriptionCharge::first()->toArray()]);
    expect($stored)->not->toContain(AGENT_KEY);
});

test('a team that never held a plan starts with the trial and is charged nothing', function () {
    config()->set('subscription.trial_days', 30);
    gatewayAnswers();

    $subscription = app(SubscribeWithCredits::class)->execute($this->team, 'agent_1gb', AGENT_KEY);

    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/charges'));
    expect($subscription->isTrialing())->toBeTrue()
        ->and($subscription->paid_until->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and(SubscriptionCharge::count())->toBe(0);
});

test('a team that already had a trial pays from the first day', function () {
    config()->set('subscription.trial_days', 30);
    Subscription::create(['team_id' => $this->team->id, 'trial_ends_at' => now()->subMonths(2)]);
    gatewayAnswers();

    app(SubscribeWithCredits::class)->execute($this->team, 'agent_1gb', AGENT_KEY);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/charges'));
});

test('a balance that cannot cover the plan leaves no plan behind and records the refusal', function () {
    gatewayAnswers(402, ['error' => ['message' => 'no'], 'balanceMicros' => 1_000_000, 'requiredMicros' => 6_000_000]);

    expect(fn () => app(SubscribeWithCredits::class)->execute($this->team, 'agent_1gb', AGENT_KEY))
        ->toThrow(fn (CreditsPaymentException $e) => expect($e->kind)->toBe(CreditsPaymentException::INSUFFICIENT));

    $subscription = Subscription::where('team_id', $this->team->id)->first();
    expect($subscription->payment_provider)->toBeNull()
        ->and($subscription->creditsPaidThrough())->toBeFalse()
        ->and(SubscriptionCharge::where('status', 'failed')->count())->toBe(1);
});

test('a key no account holds is refused before anything is charged', function () {
    Http::fake([GATEWAY.'/service-api/accounts/resolve-key' => Http::response(['error' => ['message' => 'No account holds this key']], 404)]);

    expect(fn () => app(SubscribeWithCredits::class)->execute($this->team, 'agent_1gb', AGENT_KEY))
        ->toThrow(fn (CreditsPaymentException $e) => expect($e->kind)->toBe(CreditsPaymentException::UNKNOWN_KEY));
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/charges'));
});

test('a gateway that does not answer is not recorded as a refused payment', function () {
    Http::fake([
        GATEWAY.'/service-api/accounts/resolve-key' => Http::response(['customerId' => 42, 'email' => 'a@example.com', 'balanceMicros' => 1]),
        GATEWAY.'/service-api/charges' => fn () => throw new ConnectionException('timed out'),
    ]);

    expect(fn () => app(SubscribeWithCredits::class)->execute($this->team, 'agent_1gb', AGENT_KEY))
        ->toThrow(fn (CreditsPaymentException $e) => expect($e->kind)->toBe(CreditsPaymentException::UNAVAILABLE));
    expect(SubscriptionCharge::count())->toBe(0);
});

test('a credit plan keeps working through its grace days and stops after them', function () {
    $subscription = Subscription::create(['team_id' => $this->team->id, 'payment_provider' => 'credits', 'plan_key' => 'agent_1gb', 'paid_until' => now()->subDays(2)]);
    expect($subscription->creditsPaidThrough())->toBeTrue();

    $subscription->update(['paid_until' => now()->subDays(3)->subMinute()]);
    expect($subscription->fresh()->creditsPaidThrough())->toBeFalse();
});

test('renewal charges the next month from where the paid period ends', function () {
    gatewayAnswers();
    $paidUntil = now()->addHours(3)->startOfSecond();
    $subscription = Subscription::create(['team_id' => $this->team->id, 'payment_provider' => 'credits', 'plan_key' => 'agent_1gb', 'gateway_customer_id' => 42, 'paid_until' => $paidUntil]);

    app(RenewCreditSubscriptionsJob::class)->handle(app(ChargeCreditsPeriod::class));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/charges')
        && $r['chargeRef'] === ChargeCreditsPeriod::reference($this->team->id, 'agent_1gb', $paidUntil));
    expect($subscription->fresh()->paid_until->equalTo($paidUntil->copy()->addMonthNoOverflow()))->toBeTrue();

    // Run again: the plan is paid ahead, so nothing more is charged.
    Http::fake();
    app(RenewCreditSubscriptionsJob::class)->handle(app(ChargeCreditsPeriod::class));
    Http::assertNothingSent();
});

test('a renewal the balance cannot cover leaves the paid period where it was', function () {
    gatewayAnswers(402, ['error' => ['message' => 'no'], 'balanceMicros' => 0, 'requiredMicros' => 6_000_000]);
    $paidUntil = now()->addHour()->startOfSecond();
    $subscription = Subscription::create(['team_id' => $this->team->id, 'payment_provider' => 'credits', 'plan_key' => 'agent_1gb', 'gateway_customer_id' => 42, 'paid_until' => $paidUntil]);

    app(RenewCreditSubscriptionsJob::class)->handle(app(ChargeCreditsPeriod::class));

    expect($subscription->fresh()->paid_until->equalTo($paidUntil))->toBeTrue()
        ->and(SubscriptionCharge::where('status', 'failed')->count())->toBe(1);
});

test('a plan whose renewal was stopped is not charged again', function () {
    gatewayAnswers();
    Subscription::create(['team_id' => $this->team->id, 'payment_provider' => 'credits', 'plan_key' => 'agent_1gb', 'gateway_customer_id' => 42, 'paid_until' => now()->addHour(), 'stripe_cancel_at_period_end' => true]);

    app(RenewCreditSubscriptionsJob::class)->handle(app(ChargeCreditsPeriod::class));

    Http::assertNothingSent();
});

test('a tier that lost its credit price is never charged at a guessed one', function () {
    gatewayAnswers();
    config()->set('subscription.plans.agent_1gb.price_cents', 0);
    Subscription::create(['team_id' => $this->team->id, 'payment_provider' => 'credits', 'plan_key' => 'agent_1gb', 'gateway_customer_id' => 42, 'paid_until' => now()->addHour()]);

    app(RenewCreditSubscriptionsJob::class)->handle(app(ChargeCreditsPeriod::class));

    Http::assertNothingSent();
});

test('a refused renewal waits out its retry window instead of every hourly run', function () {
    gatewayAnswers();
    $paidUntil = now()->subHour()->startOfSecond();
    Subscription::create(['team_id' => $this->team->id, 'payment_provider' => 'credits', 'plan_key' => 'agent_1gb', 'gateway_customer_id' => 42, 'paid_until' => $paidUntil]);
    SubscriptionCharge::create([
        'team_id' => $this->team->id,
        'provider' => 'credits',
        'plan_key' => 'agent_1gb',
        'amount_cents' => 600,
        'charge_ref' => "team-{$this->team->id}-agent_1gb-{$paidUntil->format('Ymd')}",
        'period_start' => $paidUntil->copy()->subMonthNoOverflow(),
        'period_end' => $paidUntil,
        'status' => 'failed',
        'failure' => 'insufficient',
    ]);

    app(RenewCreditSubscriptionsJob::class)->handle(app(ChargeCreditsPeriod::class));

    Http::assertNothingSent();

    // Past the retry window the balance is asked again, so the plan can recover once it is topped up.
    $this->travelTo(now()->addHours(7));
    app(RenewCreditSubscriptionsJob::class)->handle(app(ChargeCreditsPeriod::class));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/charges') && $r['amountCents'] === 600);
});

test('a team that had a card plan gets no second trial when it switches to credits', function () {
    config()->set('subscription.trial_days', 30);
    gatewayAnswers();
    $subscription = Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_card',
        'stripe_customer_id' => 'cus_card',
        'stripe_invoice_paid' => true,
    ]);
    $this->team->subscriptionEnded();

    app(SubscribeWithCredits::class)->execute($this->team, 'agent_1gb', AGENT_KEY);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/charges'));
    expect($subscription->fresh()->trial_ends_at)->toBeNull()
        ->and($subscription->fresh()->paid_until->isSameDay(now()->addMonthNoOverflow()))->toBeTrue();
});

test('a stale update for the abandoned card plan cannot rewrite the plan bought with credits', function () {
    gatewayAnswers();
    Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_card',
        'stripe_customer_id' => 'cus_card',
        'stripe_invoice_paid' => true,
    ]);
    $this->team->subscriptionEnded();

    app(SubscribeWithCredits::class)->execute($this->team, 'agent_1gb', AGENT_KEY);

    // Stripe redelivers the abandoned card subscription's update, or its dunning cycle emits one.
    (new StripeProcessJob(['type' => 'customer.subscription.updated', 'data' => ['object' => [
        'id' => 'sub_card', 'customer' => 'cus_card', 'status' => 'unpaid',
        'items' => ['data' => [[
            'subscription' => 'sub_card',
            'plan' => ['id' => 'price_team'],
            'price' => ['lookup_key' => 'xlaunch_deploy_team'],
            'quantity' => 10,
        ]]],
        'cancel_at_period_end' => true,
        'cancellation_details' => ['feedback' => null, 'comment' => null],
        'trial_end' => null,
    ]]]))->handle();

    $subscription = Subscription::where('team_id', $this->team->id)->first();
    $paidUntil = $subscription->paid_until;
    expect($subscription->payment_provider)->toBe('credits')
        ->and($subscription->plan_key)->toBe('agent_1gb')
        ->and($subscription->paid_until->equalTo($paidUntil))->toBeTrue()
        ->and($this->team->fresh()->custom_server_limit)->toBe(1);

    // And the credits plan still renews at its own tier's price, not the card tier's.
    $this->travelTo($paidUntil->copy()->subHours(3));
    app(RenewCreditSubscriptionsJob::class)->handle(app(ChargeCreditsPeriod::class));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/charges')
        && $r['amountCents'] === 600
        && $r['chargeRef'] === "team-{$this->team->id}-agent_1gb-{$paidUntil->format('Ymd')}");
});
