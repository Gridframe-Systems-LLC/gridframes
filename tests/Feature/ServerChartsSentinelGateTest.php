<?php

use App\Livewire\Server\Charts;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('gates the metrics page View Sentinel button behind the viewSentinel ability', function () {
    $charts = file_get_contents(resource_path('views/livewire/server/charts.blade.php'));

    // The "View Sentinel" button sits in the else-branch (build/swarm or
    // force-disabled servers) and links to server.sentinel, which SentinelShow
    // authorizes with viewSentinel — the same gate the sidebar and navbar apply.
    preg_match('/@can\(\'viewSentinel\', \$server\).*?route\(\'server\.sentinel\'.*?@endcan/s', $charts, $matches);
    expect($matches)->not->toBeEmpty();
    expect($charts)->toContain('View Sentinel');
});

it('hides the View Sentinel button from members and shows it to admins', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'owner']);
    $team->members()->attach($member->id, ['role' => 'member']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_build_server' => true, 'is_metrics_enabled' => false]);

    $this->actingAs($member);
    session(['currentTeam' => $team]);
    Livewire::test(Charts::class, ['server_uuid' => $server->uuid])
        ->assertDontSee('View Sentinel');

    $this->actingAs($admin);
    session(['currentTeam' => $team]);
    Livewire::test(Charts::class, ['server_uuid' => $server->uuid])
        ->assertSee('View Sentinel');
});
