<?php

use App\Actions\Stripe\CreateCheckoutSession;
use App\Jobs\StripeProcessJob;
use App\Jobs\VerifyStripeSubscriptionStatusJob;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.trial_days', 30);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    Subscription::create(['team_id' => $this->team->id, 'stripe_customer_id' => 'cus_trial']);

    $this->stripe = Mockery::mock(StripeClient::class);
    $this->sessions = Mockery::mock(SessionService::class);
    $this->subscriptions = Mockery::mock(SubscriptionService::class);
    $this->stripe->checkout = (object) ['sessions' => $this->sessions];
    $this->stripe->subscriptions = $this->subscriptions;
    $this->sessions->shouldReceive('all')->andReturn(stripeTrialCollection([]));
});

function stripeTrialCollection(array $data): object
{
    return new class($data)
    {
        public function __construct(public array $data) {}

        public function autoPagingIterator(): iterable
        {
            yield from $this->data;
        }
    };
}

test('a first checkout carries the trial and collects a card for the first payment', function () {
    $this->subscriptions->shouldReceive('all')->andReturn(stripeTrialCollection([]));
    $this->sessions->shouldReceive('create')->once()->withArgs(function (array $params) {
        return $params['subscription_data']['trial_period_days'] === 30
            && $params['subscription_data']['trial_settings']['end_behavior']['missing_payment_method'] === 'cancel'
            && $params['payment_method_collection'] === 'always'
            && $params['subscription_data']['metadata']['team_id'] === $this->team->id;
    })->andReturn((object) ['id' => 'cs_trial', 'url' => 'https://checkout.test', 'subscription' => null]);

    (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_personal');
});

test('a customer who ever subscribed gets no second trial', function () {
    $this->subscriptions->shouldReceive('all')->andReturn(stripeTrialCollection([(object) ['id' => 'sub_old', 'status' => 'canceled']]));
    $this->sessions->shouldReceive('create')->once()->withArgs(function (array $params) {
        return ! array_key_exists('trial_period_days', $params['subscription_data'])
            && $params['payment_method_collection'] === 'if_required';
    })->andReturn((object) ['id' => 'cs_paid', 'url' => 'https://checkout.test', 'subscription' => null]);

    (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_personal');
});

test('no trial is offered when the instance sets none', function () {
    config()->set('subscription.trial_days', 0);
    $this->subscriptions->shouldReceive('all')->andReturn(stripeTrialCollection([]));
    $this->sessions->shouldReceive('create')->once()->withArgs(fn (array $params) => ! array_key_exists('trial_period_days', $params['subscription_data']))
        ->andReturn((object) ['id' => 'cs_none', 'url' => 'https://checkout.test', 'subscription' => null]);

    (new CreateCheckoutSession($this->stripe))->execute($this->team, $this->user, 'price_personal');
});

test('the zero invoice that opens a trial keeps the team in force', function () {
    // The handler must decide this itself, not leave it to a follow-up verification job.
    \Illuminate\Support\Facades\Queue::fake();
    Subscription::where('team_id', $this->team->id)->update(['stripe_subscription_id' => 'sub_trial']);
    $this->subscriptions->shouldReceive('retrieve')->with('sub_trial')->andReturn((object) ['status' => 'trialing', 'cancel_at_period_end' => false]);
    app()->instance(StripeClient::class, $this->stripe);

    (new StripeProcessJob(['type' => 'invoice.paid', 'data' => ['object' => ['customer' => 'cus_trial', 'amount_paid' => 0, 'subscription' => 'sub_trial']]]))->handle();

    expect(Subscription::where('team_id', $this->team->id)->first()->stripe_invoice_paid)->toBeTrue();
    \Illuminate\Support\Facades\Queue::assertNotPushed(VerifyStripeSubscriptionStatusJob::class);
});

test('verifying a subscription in its trial marks it in force', function () {
    $subscription = Subscription::where('team_id', $this->team->id)->first();
    $subscription->update(['stripe_subscription_id' => 'sub_trial']);
    $this->subscriptions->shouldReceive('retrieve')->with('sub_trial')->andReturn((object) ['status' => 'trialing', 'cancel_at_period_end' => false]);
    app()->instance(StripeClient::class, $this->stripe);

    (new VerifyStripeSubscriptionStatusJob($subscription->fresh()))->handle();

    expect($subscription->fresh()->stripe_invoice_paid)->toBeTrue();
});
