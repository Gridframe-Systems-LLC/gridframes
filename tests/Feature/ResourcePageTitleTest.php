<?php

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders the source name as a large page title before the navbar', function () {
    $githubApp = GithubApp::create([
        'name' => 'xlaunch-laravel-dev-public',
        'organization' => 'northlatch',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_secret' => 'webhook-secret',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);

    $response = $this->get(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));

    $response->assertSuccessful();
    $response->assertSee('text-[24px]! leading-7! font-semibold! tracking-tight!', false);
    $response->assertSee('order-1 mb-4 flex min-w-0 items-start justify-between gap-4 lg:order-2', false);
    // The source page uses a settings sidebar instead of a fixed layer-2 tab strip.
    $response->assertSee('application-settings-navigation', false);
    $response->assertSee('xlaunch-laravel-dev-public');
    $response->assertSee('GitHub App for northlatch');
    // The General settings section renders its heading through the info helper.
    $response->assertSee('underline underline-offset-4">General</span>', false);

    $html = $response->getContent();
    $titlePos = strpos($html, 'order-1 mb-4 flex min-w-0 items-start justify-between gap-4 lg:order-2');
    // Anchor on the sidebar markup (not the inlined CSS rule) to compare positions.
    $sidebarPos = strpos($html, '<aside class="application-settings-navigation');
    expect($titlePos)->toBeLessThan($sidebarPos);
});

it('renders family page titles before tabs for team notifications and keys', function () {
    $team = $this->get(route('team.index'));
    $team->assertSuccessful();
    $team->assertSee('Team');
    $team->assertSee('Manage your team, members, and access settings.');
    $team->assertSee('New team');
    // The family settings layout hides its H1 on the wider desktop shell (xl+),
    // keeping the mobile header title.
    expect($team->getContent())->toContain('xl:hidden');

    $this->get(route('notifications.email'))
        ->assertSuccessful()
        ->assertSee('Notifications')
        ->assertSee('Configure how your team receives deployment and system alerts.');

    $keys = $this->get(route('security.private-key.index'));
    $keys->assertSuccessful();
    // assertSee escapes by default, matching the rendered "Keys & Tokens" H1.
    $keys->assertSee('Keys & Tokens');
    $keys->assertSee('Manage SSH keys, cloud credentials, and API access tokens.');
    $keys->assertSee('Private Keys');
    $keys->assertSee('New private key');
    $keys->assertDontSee('SSH keys available to this team');
    expect($keys->getContent())->not->toContain('>Private keys</h2>');
});

it('renders the destination name as a large page title', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'prod-server']);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $destination->update(['name' => 'xlaunch']);

    $response = $this->get(route('destination.show', ['destination_uuid' => $destination->uuid]));

    $response->assertSuccessful();
    $response->assertSee('text-[24px]! leading-7! font-semibold! tracking-tight!', false);
    $response->assertSee('xlaunch');
    $response->assertSee('Docker network on prod-server');
    // The General settings section renders its heading through the info helper.
    $response->assertSee('underline underline-offset-4">General</span>', false);
});

it('renders the s3 storage name as a large page title', function () {
    $storage = S3Storage::create([
        'name' => 'backup-bucket',
        'description' => 'Primary backup destination',
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'secret-key',
        'bucket' => 'xlaunch-backups',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);

    $response = $this->get(route('storage.show', ['storage_uuid' => $storage->uuid]));

    $response->assertSuccessful();
    $response->assertSee('text-[24px]! leading-7! font-semibold! tracking-tight!', false);
    $response->assertSee('backup-bucket');
    $response->assertSee('Primary backup destination');
    // The General settings section renders its heading through the info helper.
    $response->assertSee('underline underline-offset-4">General</span>', false);
});

it('uses the same page title size on application database service and server headings', function () {
    $titleClass = 'text-[24px]! leading-7! font-semibold! tracking-tight!';

    $views = [
        resource_path('views/livewire/project/application/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
        resource_path('views/livewire/server/navbar.blade.php'),
        resource_path('views/livewire/project/show.blade.php'),
        resource_path('views/livewire/project/resource/index.blade.php'),
        resource_path('views/components/dashboard/navbar.blade.php'),
        resource_path('views/livewire/server/index.blade.php'),
        resource_path('views/source/all.blade.php'),
    ];

    foreach ($views as $view) {
        expect(file_get_contents($view))->toContain($titleClass);
    }
});

it('keeps new-action buttons on the same row as page titles or in layer-2 nav', function () {
    $stackPattern = 'flex-col gap-3 sm:flex-row';

    expect(file_get_contents(resource_path('views/livewire/server/index.blade.php')))
        ->toContain($stackPattern);
    expect(file_get_contents(resource_path('views/source/all.blade.php')))
        ->toContain($stackPattern);
    expect(file_get_contents(resource_path('views/livewire/destination/index.blade.php')))
        ->toContain($stackPattern);
    expect(file_get_contents(resource_path('views/livewire/storage/index.blade.php')))
        ->toContain($stackPattern);
    expect(file_get_contents(resource_path('views/components/dashboard/navbar.blade.php')))
        ->toContain('titleActions');
    // Family pages put primary actions next to layer-2 tabs, not beside a desktop H1.
    expect(file_get_contents(resource_path('views/livewire/security/private-key/index.blade.php')))
        ->toContain('<x-slot:actions>');
    expect(file_get_contents(resource_path('views/components/team/navbar.blade.php')))
        ->toContain("'titleOnDesktop' => false")
        ->toContain('<x-slot:actions>')
        ->toContain('New team');
});
