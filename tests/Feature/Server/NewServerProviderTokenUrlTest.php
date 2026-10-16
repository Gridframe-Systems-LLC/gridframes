<?php

use App\Livewire\Server\CreatePage;
use App\Livewire\Server\New\ByDigitalOcean;
use App\Livewire\Server\New\ByHetzner;
use App\Livewire\Server\New\ByVultr;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

test('provider token selection redirects to a token specific server creation url', function (string $component, string $provider, string $type) {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
    ]);

    Livewire::test($component)
        ->call('selectToken', $token->id)
        ->assertRedirect(route('server.create.token', [
            'type' => $type,
            'token_uuid' => $token->uuid,
        ]));
})->with([
    'hetzner' => [ByHetzner::class, 'hetzner', 'hetzner'],
    'vultr' => [ByVultr::class, 'vultr', 'vultr'],
    'digital-ocean' => [ByDigitalOcean::class, 'digitalocean', 'digital-ocean'],
]);

test('provider token selection is rendered as clickable token boxes', function (string $component, string $provider, string $type, string $tokenName) {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
        'name' => $tokenName,
    ]);

    Livewire::test($component)
        ->assertSee($tokenName)
        ->assertSee(route('server.create.token', ['type' => $type, 'token_uuid' => $token->uuid]))
        ->assertSee("{$provider}-token-{$token->id}", false)
        ->assertDontSee('loadingProviderTokenId')
        ->assertDontSee('coolbox-loading', false)
        ->assertDontSee('wire:click="selectToken(', false)
        ->assertDontSee('loading-spinner', false)
        ->assertDontSee('Loading Hetzner details...')
        ->assertDontSee('Loading Vultr details...')
        ->assertDontSee('Loading DigitalOcean details...')
        ->assertDontSee('Select a saved token')
        ->assertDontSee('You need a saved')
        ->assertDontSee('Continue');
})->with([
    'hetzner' => [ByHetzner::class, 'hetzner', 'hetzner', 'Production Hetzner'],
    'vultr' => [ByVultr::class, 'vultr', 'vultr', 'Production Vultr'],
    'digital-ocean' => [ByDigitalOcean::class, 'digitalocean', 'digital-ocean', 'Production DigitalOcean'],
]);

test('provider token selection shows an add token box when no tokens exist', function (string $component, string $title, string $providerName) {
    Livewire::test($component)
        ->assertSee($title)
        ->assertSee('Add an API token to continue provisioning.')
        ->assertSee('Add token')
        ->assertSee('Add '.$providerName.' Token')
        ->assertSee('M12 18V6', false)
        ->assertDontSee('wire:click="selectToken(', false);
})->with([
    'hetzner' => [ByHetzner::class, 'No Hetzner tokens', 'Hetzner'],
    'vultr' => [ByVultr::class, 'No Vultr tokens', 'Vultr'],
    'digital-ocean' => [ByDigitalOcean::class, 'No DigitalOcean tokens', 'DigitalOcean'],
]);

test('provider token urls pass the token uuid into the server creation page', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
    ]);

    expect(route('server.create.token', [
        'type' => 'hetzner',
        'token_uuid' => $token->uuid,
    ]))->toEndWith('/servers/new/hetzner/'.$token->uuid);

    Livewire::test(CreatePage::class, [
        'type' => 'hetzner',
        'token_uuid' => $token->uuid,
    ])
        ->assertSet('type', 'hetzner')
        ->assertSet('token_uuid', $token->uuid)
        ->assertSet('title', 'Hetzner');
});

test('provider token pages defer provider api data loading until wire init', function (string $component, string $provider, string $type, string $loadingText, string $wireInitCall) {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
    ]);

    Http::fake([
        '*' => Http::response([], 200),
    ]);

    Livewire::test($component, ['selectedTokenUuid' => $token->uuid])
        ->assertSet('current_step', 2)
        ->assertSet('loading_data', true)
        ->assertSee($loadingText)
        ->assertSee($wireInitCall, false)
        ->assertSee('loading-indicator shrink-0 animate-spin', false)
        ->assertDontSee('border-b-2 border-primary', false);

    Http::assertNothingSent();
})->with([
    'hetzner' => [ByHetzner::class, 'hetzner', 'hetzner', 'Loading Hetzner data...', 'wire:init="loadHetznerData"'],
    'vultr' => [ByVultr::class, 'vultr', 'vultr', 'Loading Vultr data...', 'wire:init="loadVultrData"'],
    'digital-ocean' => [ByDigitalOcean::class, 'digitalocean', 'digital-ocean', 'Loading DigitalOcean data...', 'wire:init="loadDigitalOceanData"'],
]);

test('provider token pages render api error details when a selected token is invalid', function (string $component, string $provider, string $type, string $loadMethod, string $providerName, string $apiMessage, string $responseBody, string $expectedError) {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
        'name' => $providerName.' Token',
    ]);

    Http::fake([
        '*' => Http::response($responseBody, 401),
    ]);

    Livewire::test($component, ['selectedTokenUuid' => $token->uuid])
        ->call($loadMethod)
        ->assertSet('loading_data', false)
        ->assertSet('provider_data_error', $expectedError)
        ->assertDispatched('error')
        ->assertSee("Unable to load {$providerName}")
        ->assertSee('Provider request failed')
        ->assertSee($apiMessage)
        ->assertSee('href="'.route('server.create.type', ['type' => $type]).'"', false)
        ->assertSee('wire:navigate', false)
        ->assertDontSee('wire:click="previousStep"', false);
})->with([
    // Vultr/DigitalOcean fetch through their services, which wrap the API
    // body in their own "… API error:" prefix before the Livewire component
    // prefixes again — the doubled prefix in $expectedError is current
    // behavior, flagged to triage.
    'hetzner' => [ByHetzner::class, 'hetzner', 'hetzner', 'loadHetznerData', 'Hetzner', 'the token you have provided is invalid', 'the token you have provided is invalid', 'Hetzner API error: the token you have provided is invalid'],
    'vultr' => [ByVultr::class, 'vultr', 'vultr', 'loadVultrData', 'Vultr', 'Invalid API key', '{"error":"Invalid API key"}', 'Vultr API error: Vultr API error: Invalid API key'],
    'digital-ocean' => [ByDigitalOcean::class, 'digitalocean', 'digital-ocean', 'loadDigitalOceanData', 'DigitalOcean', 'Unable to authenticate you', '{"message":"Unable to authenticate you"}', 'DigitalOcean API error: DigitalOcean API error: Unable to authenticate you'],
]);

test('back button on token specific provider creation pages returns to the method chooser', function (string $provider, string $type) {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
    ]);

    Livewire::test(CreatePage::class, [
        'type' => $type,
        'token_uuid' => $token->uuid,
    ])
        ->assertSee('Change method')
        ->assertSee('href="'.route('server.create').'"', false)
        ->assertDontSee('href="'.route('server.create.type', ['type' => $type]).'"', false);
})->with([
    'hetzner' => ['hetzner', 'hetzner'],
    'vultr' => ['vultr', 'vultr'],
    'digital-ocean' => ['digitalocean', 'digital-ocean'],
]);

test('the provider token picker only renders on provider pages without a selected token', function (string $provider, string $type, string $providerName) {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
    ]);

    Livewire::test(CreatePage::class, [
        'type' => $type,
    ])
        ->assertSee($providerName.' account');

    Livewire::test(CreatePage::class, [
        'type' => $type,
        'token_uuid' => $token->uuid,
    ])
        ->assertDontSee($providerName.' account')
        ->assertSee("Loading {$providerName} data...");
})->with([
    'hetzner' => ['hetzner', 'hetzner', 'Hetzner'],
    'vultr' => ['vultr', 'vultr', 'Vultr'],
    'digital-ocean' => ['digitalocean', 'digital-ocean', 'DigitalOcean'],
]);
