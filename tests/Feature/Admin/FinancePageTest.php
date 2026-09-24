<?php

use App\Livewire\Admin\Finance;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Stripe\Service\InvoiceService;
use Stripe\Service\PriceService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('constants.xlaunch.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.license_share_percent', 30.0);
    config()->set('subscription.plans', [
        'agent_1gb' => ['name' => 'Agent 1 GB', 'price_id' => 'price_a1', 'price_label' => '$6', 'price_cents' => 600, 'servers' => 1, 'tagline' => 't', 'machine' => '', 'lookup_key' => 'a1'],
        'team' => ['name' => 'Team', 'price_id' => 'price_team', 'price_label' => '$25', 'price_cents' => 2500, 'servers' => 10, 'tagline' => 't', 'machine' => '', 'lookup_key' => 'team'],
    ]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $this->root = User::find(0) ?? User::factory()->create(['id' => 0]);
    $this->rootTeam->members()->syncWithoutDetaching([$this->root->id => ['role' => 'admin']]);

    $this->stripe = Mockery::mock(StripeClient::class);
    $prices = Mockery::mock(PriceService::class);
    $invoices = Mockery::mock(InvoiceService::class);
    $prices->shouldReceive('retrieve')->with('price_a1')->andReturn((object) ['unit_amount' => 600]);
    $prices->shouldReceive('retrieve')->with('price_team')->andReturn((object) ['unit_amount' => 2000]);
    $invoices->shouldReceive('all')->andReturn(new class
    {
        public function autoPagingIterator(): iterable
        {
            yield (object) ['amount_paid' => 2500];
        }
    });
    $this->stripe->prices = $prices;
    $this->stripe->invoices = $invoices;
    app()->instance(StripeClient::class, $this->stripe);
});

test('only the root operator can open the finance page', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    Livewire::test(Finance::class)->assertForbidden();
});

test('the operator sees revenue by card and by credits, the licence share, and a price that drifted from Stripe', function () {
    $credit = Team::factory()->create();
    Subscription::create(['team_id' => $credit->id, 'payment_provider' => 'credits', 'plan_key' => 'agent_1gb', 'paid_until' => now()->addDays(20)]);
    SubscriptionCharge::create(['team_id' => $credit->id, 'provider' => 'credits', 'plan_key' => 'agent_1gb', 'amount_cents' => 600, 'charge_ref' => 'team-x-agent_1gb-1', 'period_start' => now(), 'period_end' => now()->addMonth(), 'status' => 'paid']);
    $card = Team::factory()->create();
    Subscription::create(['team_id' => $card->id, 'stripe_plan_id' => 'price_team', 'stripe_invoice_paid' => true]);

    $this->actingAs($this->root);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(Finance::class)
        ->assertSee('$31.00')      // recurring: $6 by credits + $25 by card
        ->assertSee('$25.00')      // card revenue this month, from Stripe
        ->assertSee('$9.30')       // 30% of $31 taken this month
        ->assertSee('Matches Stripe')
        ->assertSee('Stripe charges $20.00, credits charge $25.00');
});

test('card revenue Stripe could not report is shown as not measured, never as zero', function () {
    $failing = Mockery::mock(InvoiceService::class);
    $failing->shouldReceive('all')->andThrow(new \Stripe\Exception\ApiConnectionException('down'));
    $this->stripe->invoices = $failing;

    $this->actingAs($this->root);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(Finance::class)->assertSee('Not measured')->assertDontSee('0 paid invoices');
});
