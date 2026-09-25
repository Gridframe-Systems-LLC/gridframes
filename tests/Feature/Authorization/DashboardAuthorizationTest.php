<?php

use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setupDashboardUser(string $role): array
{
    $team = Team::factory()->create();

    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);

    return [$user, $team];
}

function createProjectForTeam(Team $team): void
{
    Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $team->id,
    ]);
}

function createPrivateKeyForTeam(Team $team): void
{
    DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('admin sees resource-create affordances on project cards', function () {
    [$user, $team] = setupDashboardUser('admin');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createProjectForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee('Test Project')
        ->assertSee('Add resource')
        ->assertSee('Project settings');
});

test('member sees projects but not the resource-create affordances', function () {
    [$user, $team] = setupDashboardUser('member');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createProjectForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee('Test Project')
        ->assertDontSee('Add resource')
        ->assertDontSee('Project settings');
});

test('admin sees add server button in the servers empty state', function () {
    [$user, $team] = setupDashboardUser('admin');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    // The create affordance lives in the empty state, shown once the team owns a
    // private key but no server yet.
    createPrivateKeyForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee(route('server.create'));
});

test('member does not see add server button in the servers empty state', function () {
    [$user, $team] = setupDashboardUser('member');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createPrivateKeyForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertDontSee(route('server.create'));
});
