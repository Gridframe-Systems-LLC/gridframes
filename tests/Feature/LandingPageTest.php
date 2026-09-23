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
