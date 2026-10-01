<?php

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('gates the navbar sentinel status link behind the viewSentinel ability', function () {
    $navbar = file_get_contents(resource_path('views/livewire/server/navbar.blade.php'));

    preg_match('/\$showSentinelStatus\s*=\s*([^;]+);/', $navbar, $matches);
    expect($matches)->not->toBeEmpty();

    // The status-summary sentinel link points at server.sentinel, which
    // SentinelShow authorizes with viewSentinel — the sidebar menu and the
    // navbar Sentinel tab already apply the same can-gate.
    expect($matches[1])
        ->toContain('$server->isFunctional()')
        ->toContain('$server->isSentinelEnabled()')
        ->toContain("can('viewSentinel', \$server)");
});

it('resolves viewSentinel to false for members and true for admins', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'owner']);
    $team->members()->attach($member->id, ['role' => 'member']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    expect($admin->can('viewSentinel', $server))->toBeTrue();
    expect($member->can('viewSentinel', $server))->toBeFalse();
});
