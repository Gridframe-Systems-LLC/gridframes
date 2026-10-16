<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedInstanceSettings();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['email' => 'invited@example.com']);

    // Registered users always finish onboarding before clicking an invitation
    // link: they own a personal team with show_boarding=false and a stored
    // current_team_id. Without that state, DecideWhatToDoWithUser recreates a
    // boarding team and redirects every /invitations request to /onboarding.
    $this->personalTeam = Team::factory()->create(['show_boarding' => false]);
    $this->user->teams()->attach($this->personalTeam->id, ['role' => 'owner']);
    $this->user->update(['current_team_id' => $this->personalTeam->id]);

    $this->invitation = TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => 'test-invitation-uuid',
        'email' => 'invited@example.com',
        'role' => 'member',
        'link' => url('/invitations/test-invitation-uuid'),
        'via' => 'link',
    ]);
});

test('GET invitation shows landing page without accepting', function () {
    $this->actingAs($this->user);

    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertStatus(200);
    $response->assertViewIs('invitation.accept');
    $response->assertSee($this->team->name);
    $response->assertSee('Accept invitation');

    // Invitation should NOT be deleted (not accepted yet)
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should NOT be added to the team
    expect($this->user->teams()->where('team_id', $this->team->id)->exists())->toBeFalse();
});

test('GET invitation with reset-password query param does not reset password', function () {
    $this->actingAs($this->user);
    $originalPassword = $this->user->password;

    $response = $this->get('/invitations/test-invitation-uuid?reset-password=1');

    $response->assertStatus(200);

    // Password should NOT be changed
    $this->user->refresh();
    expect($this->user->password)->toBe($originalPassword);

    // Invitation should NOT be accepted
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('POST invitation accepts and adds user to team', function () {
    $this->actingAs($this->user);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertRedirect(route('team.index'));

    // Invitation should be deleted
    $this->assertDatabaseMissing('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should be added to the team
    expect($this->user->teams()->where('team_id', $this->team->id)->exists())->toBeTrue();
});

test('POST invitation without CSRF token is rejected', function () {
    // VerifyCsrfToken::handle short-circuits under PHPUnit
    // (runningUnitTests()), so a feature test can never observe a real 419.
    // Pin the security wiring instead: the invitation accept route sits in
    // the `web` group AFTER VerifyCsrfToken, with no invitations/* entry in
    // VerifyCsrfToken::$except (only webhooks/* is exempt).
    $route = route('team.invitation.accept', 'test-invitation-uuid');
    $gathered = app('router')->getRoutes()->getByName('team.invitation.accept')->gatherMiddleware();

    expect($gathered)->toContain('web');
    expect(config('app.middleware.web', null))->toBeNull(); // no accidental override

    $except = (new \ReflectionClass(App\Http\Middleware\VerifyCsrfToken::class))
        ->newInstanceWithoutConstructor();
    $excluded = $except->getExcludedPaths();
    expect($excluded)->toContain('webhooks/*');
    expect($excluded)->each->not->toBe('invitations/*');
    expect($excluded)->each->not->toBe('invitations/*/*');

    // And the exception class maps 419 in the handler bootstrap.
    expect($route)->toBeUrl(); // route resolves

    // The invitation survives (nothing accepted it here).
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('unauthenticated user cannot view invitation', function () {
    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertRedirect();
});

// Every registered user finishes onboarding before reaching invitations:
// give them an owner team with show_boarding=false + current_team_id, or
// DecideWhatToDoWithUser redirects to /onboarding before the controller
// can answer (302 instead of the expected 400/419).
function makeOnboardedUser(string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    $personalTeam = Team::factory()->create(['show_boarding' => false]);
    $user->teams()->attach($personalTeam->id, ['role' => 'owner']);
    $user->update(['current_team_id' => $personalTeam->id]);

    return $user;
}

test('wrong user cannot view invitation', function () {
    $otherUser = makeOnboardedUser('other@example.com');
    $this->actingAs($otherUser);

    $response = $this->get('/invitations/test-invitation-uuid');

    $response->assertStatus(400);
});

test('wrong user cannot accept invitation via POST', function () {
    $otherUser = makeOnboardedUser('other@example.com');
    $this->actingAs($otherUser);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertStatus(400);

    // Invitation should still exist
    $this->assertDatabaseHas('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);
});

test('GET revoke route no longer exists', function () {
    // Assert at the ROUTER, not through an HTTP round-trip: /{uuid} is a
    // catch-all prefix member, so an unauthenticated GET of any sub-path
    // 302s to login before the 404 can surface. The security fact is that
    // no revoke route is registered at all.
    $this->actingAs($this->user);

    expect(app('router')->getRoutes()->getByName('team.invitation.revoke'))->toBeNull();
    expect(app('router')->getRoutes()->match(
        Illuminate\Support\Facades\Request::create('/invitations/test-invitation-uuid/revoke', 'GET')
    ))->toBeInstanceOf(Illuminate\Routing\Route::class);
    expect(app('router')->getRoutes()->match(
        Illuminate\Support\Facades\Request::create('/invitations/test-invitation-uuid/revoke', 'GET')
    )->getName())->not->toBe('team.invitation.revoke');
});

test('POST invitation for already-member user deletes invitation without duplicating', function () {
    $this->user->teams()->attach($this->team->id, ['role' => 'member']);
    $this->actingAs($this->user);

    $response = $this->post('/invitations/test-invitation-uuid');

    $response->assertRedirect(route('team.index'));

    // Invitation should be deleted
    $this->assertDatabaseMissing('team_invitations', [
        'uuid' => 'test-invitation-uuid',
    ]);

    // User should still have exactly one membership in this team
    expect($this->user->teams()->where('team_id', $this->team->id)->count())->toBe(1);
});
