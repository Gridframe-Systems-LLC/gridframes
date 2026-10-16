<?php

it('allows selecting Vultr in cloud provider token forms', function () {
    $view = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-token-form.blade.php');
    $component = file_get_contents(__DIR__.'/../../app/Livewire/Security/CloudProviderTokenForm.php');

    expect($view)->toContain('<x-forms.listbox required id="provider" label="Provider"')
        ->and($view)->toContain("['value' => 'vultr', 'label' => 'Vultr']")
        ->and($view)->toContain("['value' => 'digitalocean', 'label' => 'DigitalOcean']")
        ->and($view)->toContain('Create the token in the')
        ->and(substr_count($view, 'https://console.vultr.com/user/apiaccess/'))->toBe(1)
        ->and($view)->not->toContain('cloudProviderTokens->where(\'provider\', $provider)->isEmpty()')
        ->and($view)->not->toContain('<x-forms.select required id="provider" label="Provider" disabled>')
        ->and($component)->toContain("'provider' => 'required|string|in:hetzner,digitalocean,vultr'");
});

it('keeps provider affiliate links on server provider views', function () {
    $tokenFormView = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-token-form.blade.php');
    $hetznerView = file_get_contents(__DIR__.'/../../resources/views/livewire/server/new/by-hetzner.blade.php');
    $vultrView = file_get_contents(__DIR__.'/../../resources/views/livewire/server/new/by-vultr.blade.php');
    $digitalOceanView = file_get_contents(__DIR__.'/../../resources/views/livewire/server/new/by-digital-ocean.blade.php');

    expect($tokenFormView)
        ->not->toContain('https://projectxprotocol.dev/hetzner')
        ->not->toContain('https://projectxprotocol.dev/vultr')
        ->and($hetznerView)->toContain('https://projectxprotocol.dev/hetzner')
        ->and($vultrView)->toContain('https://projectxprotocol.dev/vultr')
        ->and($digitalOceanView)->toContain('https://projectxprotocol.dev/digitalocean');
});

it('uses a provider dropdown and modal forms on cloud provider tokens page', function () {
    $view = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-tokens.blade.php');

    expect($view)->toContain('<x-modal-input title="New Cloud Token">')
        ->and($view)->toContain('New token')
        ->and($view)->toContain('<div class="flex min-w-0 items-center gap-3">')
        ->and($view)->toContain('<div class="grid grid-cols-[minmax(0,1fr)_8rem_1.75rem] items-center gap-3 border-b')
        ->and($view)->toContain('<x-empty title="No cloud tokens"')
        ->and($view)->toContain('description="Add a provider token to provision new cloud servers."')
        ->and($view)->toContain('<x-application.settings-section title="Cloud tokens"')
        ->and($view)->toContain('description="Provider credentials used to provision cloud servers." flush>')
        ->and($view)->toContain('<livewire:security.cloud-provider-token-form :modal_mode="true" wire:key="new-cloud-provider-token" />')
        ->and($view)->toContain('<x-modal-input title="Edit Cloud Token" isFullWidth')
        ->and($view)->not->toContain('<h3>New Token</h3>')
        ->and($view)->not->toContain(':modal_mode="false"')
        ->and($view)->not->toContain('wire:click="validateToken')
        ->and($view)->not->toContain('submitAction="deleteToken')
        ->and($view)->not->toContain('Created {{ $savedToken->created_at->diffForHumans() }}');
});

it('shows explicit loading spinners and disables cloud token action buttons while requests run', function () {
    $tokenFormView = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-token-form.blade.php');
    $tokenShowView = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-token/show.blade.php');
    $serverTokenView = file_get_contents(__DIR__.'/../../resources/views/livewire/server/cloud-provider-token/show.blade.php');

    expect($tokenFormView)->toContain('wire:target="addToken"')
        ->and($tokenFormView)->toContain('<x-forms.button type="submit"')
        ->and($tokenShowView)->toContain('wire:click="validateToken"')
        ->and($tokenShowView)->toContain('<x-forms.button type="button" wire:click="validateToken">')
        ->and($serverTokenView)->toContain('wire:click.prevent="validateToken"')
        ->and($serverTokenView)->toContain('<x-forms.button canGate="update" :canResource="$server"');
});
