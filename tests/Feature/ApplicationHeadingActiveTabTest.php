<?php

use App\Livewire\Project\Application\Heading as ApplicationHeading;
use App\Livewire\Project\Application\Status as ApplicationStatus;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
    ]);

    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'xlaunch'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);

    $this->routeParams = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ];
});

/**
 * Settings sub-pages no longer mark a "Settings" tab in the application
 * heading (that nav moved into the configuration sidebar). Each sub-route
 * must instead mark its own sidebar item with menu-item-active so the
 * current section is visibly highlighted.
 */
function assertSettingsNavItemActive(string $html, string $label): void
{
    expect(preg_match(
        '/<a[^>]*menu-item-active[^>]*>.*?<span class="menu-item-label">'.preg_quote($label, '/').'<\/span>/s',
        $html
    ))->toBe(1);

    // Desktop navbar CSS override must exist so active tab styles are visible.
    $css = file_get_contents(resource_path('css/app.css'));
    expect($css)
        ->toContain(".application-heading-actions .app-tab[aria-current='page']")
        ->toContain('.application-heading-actions .app-tab.app-tab-active');
}

it('marks the General settings item active on the general configuration route', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $html = $this->get(route('project.application.configuration', $this->routeParams))
        ->assertSuccessful()
        ->getContent();

    assertSettingsNavItemActive($html, 'General');
});

it('marks its own settings item active on settings sub-routes', function (string $routeName, string $label) {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $html = $this->get(route($routeName, $this->routeParams))
        ->assertSuccessful()
        ->getContent();

    assertSettingsNavItemActive($html, $label);
})->with([
    'webhooks' => ['project.application.webhooks', 'Webhooks'],
    'domains' => ['project.application.domains', 'Domains'],
    'advanced' => ['project.application.advanced', 'Advanced'],
    'environment-variables' => ['project.application.environment-variables', 'Environment Variables'],
    'danger' => ['project.application.danger', 'Danger Zone'],
]);

it('does not mark settings tab active on deployment logs', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $html = $this->get(route('project.application.deployment.index', $this->routeParams))
        ->assertSuccessful()
        ->getContent();

    expect($html)->toContain('Deployment');

    expect(preg_match(
        '/<a[^>]*(?:aria-current="page"[^>]*app-tab-active|app-tab-active[^>]*aria-current="page")[^>]*>\s*Settings\s*<\/a>/s',
        $html
    ))->toBe(0);

    // Deployment now lives in the Logs section of the settings sidebar.
    expect(preg_match(
        '/<a[^>]*menu-item-active[^>]*>.*?Deployment.*?<\/a>/s',
        $html
    ))->toBe(1);
});

it('syncs activeRouteName from the page route when heading is rendered on webhooks', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $html = $this->get(route('project.application.webhooks', $this->routeParams))
        ->assertSuccessful()
        ->assertSeeLivewire(ApplicationHeading::class)
        ->getContent();

    assertSettingsNavItemActive($html, 'Webhooks');
});

it('keeps activeRouteName when request is not an application page route', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(ApplicationHeading::class, ['application' => $this->application]);
    $component->set('activeRouteName', 'project.application.webhooks');

    $component->call('$refresh')
        ->assertSet('activeRouteName', 'project.application.webhooks');
});

it('refreshes the breadcrumb application status after it changes', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(ApplicationStatus::class, ['application' => $this->application])
        ->assertSee('Running');

    $this->application->update(['status' => 'exited']);

    $component
        ->call('refreshStatus')
        ->assertSee('Exited')
        ->assertDontSee('Running');

    expect($component->instance()->getListeners())
        ->toHaveKey("echo-private:team.{$this->team->id},ServiceChecked", 'refreshStatus');
});

it('uses app-tab-active utility for resource heading active styles', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    expect($utilities)->toContain('@utility app-tab-active');
});
