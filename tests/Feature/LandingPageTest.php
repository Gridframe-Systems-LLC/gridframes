<?php

use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->create(['id' => 0, 'is_registration_enabled' => true])
    );

    config()->set('landing.enabled', true);
    config()->set('subscription.provider', 'stripe');
    config()->set('landing.brand', 'GridFrame Systems');
    config()->set('landing.headline', 'Your own cloud, on machines you can point at.');
    config()->set('subscription.plans', [
        'personal' => [
            'name' => 'Personal',
            'price_id' => 'price_personal',
            'price_label' => '$20',
            'servers' => 3,
            'machine' => '2 vCPU · 8 GB RAM',
            'tagline' => 'One person, a few machines.',
            'lookup_key' => 'xlaunch_deploy_personal',
        ],
    ]);
});

it('shows the landing page to a stranger at the apex', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('GridFrame Systems')
        ->assertSee('Your own cloud, on machines you can point at.');
});

it('quotes the price and the machine the instance actually sells', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('$20')
        ->assertSee('2 vCPU · 8 GB RAM', escape: false);
});

it('never advertises a tier that cannot be bought', function () {
    config()->set('subscription.plans.ghost', [
        'name' => 'Ghost',
        'price_id' => null,
        'price_label' => '$99',
        'servers' => 50,
        'machine' => '',
        'tagline' => 'A tier with no price attached.',
        'lookup_key' => 'ghost',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Ghost')
        ->assertDontSee('$99');
});

it('offers sign-up only while registration is open', function () {
    $this->get('/')->assertSee('Create an account');

    InstanceSettings::get()->update(['is_registration_enabled' => false]);
    Once::flush();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Create an account')
        ->assertSee('by invitation');
});

it('sends a signed-in customer to their dashboard instead of the pitch', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    // Onboarding has its own opinion about where a brand-new account belongs, and it runs first.
    // What is under test here is only the choice the apex itself makes for someone with a session.
    $this->actingAs($user)
        ->withoutMiddleware(DecideWhatToDoWithUser::class)
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('keeps a private instance private by handing the visitor the sign-in form', function () {
    config()->set('landing.enabled', false);

    $this->get('/')->assertRedirect(route('login'));
});

it('shows every tier on sale and the trial when the instance gives one', function () {
    config()->set('subscription.trial_days', 30);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.plans', [
        'agent_1gb' => ['name' => 'Agent 1 GB', 'price_id' => 'price_a1', 'price_label' => '$6', 'price_cents' => 600, 'servers' => 1, 'machine' => 'shared vCPU · 1 GB RAM', 'tagline' => 'One agent.', 'lookup_key' => 'a1'],
        'agent_2gb' => ['name' => 'Agent 2 GB', 'price_id' => 'price_a2', 'price_label' => '$12', 'price_cents' => 1200, 'servers' => 1, 'machine' => '', 'tagline' => 'Two.', 'lookup_key' => 'a2'],
        'personal' => ['name' => 'Personal', 'price_id' => 'price_p', 'price_label' => '$20', 'price_cents' => 2000, 'servers' => 3, 'machine' => '', 'tagline' => 'One person.', 'lookup_key' => 'p'],
        'team' => ['name' => 'Team', 'price_id' => 'price_t', 'price_label' => '$25', 'price_cents' => 2500, 'servers' => 10, 'machine' => '', 'tagline' => 'A team.', 'lookup_key' => 't'],
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSeeInOrder(['Agent 1 GB', 'Agent 2 GB', 'Personal', 'Team'])
        ->assertSee('Machines from $6')
        ->assertSee('Start 30-day free trial')
        ->assertSee('Every plan starts with a 30-day free trial');
});

it('draws the operator block wordmark and favicon when they are configured', function () {
    config()->set('landing.logo', '/brand/gridframes/logo.svg');
    config()->set('landing.logo_words', 'gridframes');
    config()->set('landing.logo_split', 4);
    config()->set('landing.favicon', '/brand/gridframes/favicon.svg');

    $this->get('/')
        ->assertOk()
        ->assertSee('data-logo="gridframes"', false)
        ->assertSee('data-logo-split="4"', false)
        ->assertSee('aspect-ratio: 59 / 9', false)
        ->assertSee('/brand/gridframes/logo.svg', false)
        ->assertSee('href="/brand/gridframes/favicon.svg"', false)
        ->assertSee('js/brand-blocks.js', false);
});

it('names the brand in plain text when no wordmark is configured', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('data-logo=', false)
        ->assertDontSee('brand-blocks.js', false);
});
