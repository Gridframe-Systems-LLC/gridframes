<?php

use App\Actions\Stripe\RefundSubscription;
use App\Livewire\Subscription\Actions;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.xlaunch.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.stripe_api_key', 'sk_test_fake');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_test_123',
        'stripe_customer_id' => 'cus_test_123',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_123',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('cancelImmediately with refund option', function () {
    test('refunds and cancels via RefundSubscription when refund checkbox is selected', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'error' => null]);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment'])
            ->assertDispatched('success')
            ->assertRedirect(route('subscription.index'));
    });

    test('dispatches error when refund fails', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => false, 'error' => 'No paid invoice found to refund.']);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment'])
            ->assertDispatched('error');
    });

    test('rejects invalid password before refunding', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldNotReceive('execute');
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'wrong-password', ['refundLatestPayment'])
            ->assertReturned('Invalid password.');
    });
});

describe('money actions require an admin of the current team', function () {
    test('a member cannot refund the subscription', function () {
        $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->user->unsetRelation('teams');

        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldNotReceive('execute');
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('refundSubscription', 'password')
            ->assertForbidden();
    });

    test('a member cannot change the server limit', function () {
        $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->user->unsetRelation('teams');

        Livewire::test(Actions::class)
            ->call('updateQuantity', '')
            ->assertForbidden();
    });

    test('a component rendered for an owned team cannot act on a team the user is only a member of', function () {
        $owned = Team::factory()->create();
        $owned->members()->attach($this->user->id, ['role' => 'owner']);
        $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->user->unsetRelation('teams');

        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldNotReceive('execute');
        $this->instance(RefundSubscription::class, $mock);

        // Rendered while the owned team is current; a replayed call after the session moved to the
        // member team must not move money on the team it lands on.
        $rendered = Livewire::test(Actions::class);
        session(['currentTeam' => ['id' => $this->team->id]]);

        $rendered->call('refundSubscription', 'password')->assertForbidden();

        Livewire::test(Actions::class)
            ->call('updateQuantity', '')
            ->assertForbidden();
    });
});
