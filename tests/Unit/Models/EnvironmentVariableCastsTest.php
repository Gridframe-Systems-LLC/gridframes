<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function castsTestApplication(): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
    ]);
}

test('is_literal and is_shown_once read as booleans after a database round-trip', function () {
    $application = castsTestApplication();

    EnvironmentVariable::create([
        'key' => 'LITERAL_FLAG',
        'value' => 'some-value',
        'is_literal' => true,
        'is_shown_once' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $variable = EnvironmentVariable::query()->firstWhere('key', 'LITERAL_FLAG');

    // The OpenAPI schema declares these flags boolean; without the cast they
    // leak the underlying integer storage (1/0) to API consumers.
    expect($variable->is_literal)->toBeBool()->toBeTrue();
    expect($variable->is_shown_once)->toBeBool()->toBeTrue();
});

test('is_literal serializes as a JSON boolean in API output', function () {
    $application = castsTestApplication();

    EnvironmentVariable::create([
        'key' => 'LITERAL_FLAG',
        'value' => 'some-value',
        'is_literal' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $variable = EnvironmentVariable::query()->firstWhere('key', 'LITERAL_FLAG');

    expect(json_decode($variable->toJson(), true)['is_literal'])->toBe(true);
});

test('false flags stay false through a round-trip', function () {
    $application = castsTestApplication();

    EnvironmentVariable::create([
        'key' => 'PLAIN_FLAG',
        'value' => 'some-value',
        'is_literal' => false,
        'is_shown_once' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $variable = EnvironmentVariable::query()->firstWhere('key', 'PLAIN_FLAG');

    expect($variable->is_literal)->toBeBool()->toBeFalse();
    expect($variable->is_shown_once)->toBeBool()->toBeFalse();
    expect($variable->is_multiline)->toBeBool()->toBeFalse();
    expect($variable->is_runtime)->toBeBool()->toBeTrue();
    expect($variable->is_buildtime)->toBeBool()->toBeTrue();
});
