<?php

/**
 * Unit tests for PORT environment variable detection feature.
 *
 * Tests verify that the Application model can correctly detect PORT environment
 * variables and provide information to the UI about matches and mismatches with
 * the configured ports_exposes field.
 *
 * Real DB-backed Application + EnvironmentVariable rows (resourceable morph);
 * the encrypted `value` cast only round-trips through the database, so the
 * real_value accessor needs persisted rows — no Mockery on Eloquent.
 */

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makePortDetectionApplication(): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
    ]);
}

function makePortEnvVar(Application $application, string $key, ?string $value, bool $isPreview = false): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => $key,
        'value' => $value,
        'is_preview' => $isPreview,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);
}

it('detects PORT environment variable when present', function () {
    $application = makePortDetectionApplication();
    makePortEnvVar($application, 'PORT', '3000');

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBe(3000);
});

it('returns null when PORT environment variable is not set', function () {
    $application = makePortDetectionApplication();
    makePortEnvVar($application, 'OTHER_VAR', 'some-value');

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBeNull();
});

it('returns null when PORT value is not numeric', function () {
    $application = makePortDetectionApplication();
    makePortEnvVar($application, 'PORT', 'invalid-port');

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBeNull();
});

it('handles PORT value with whitespace', function () {
    $application = makePortDetectionApplication();
    makePortEnvVar($application, 'PORT', '  8080  ');

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBe(8080);
});

it('detects PORT from preview environment variables when isPreview is true', function () {
    $application = makePortDetectionApplication();
    makePortEnvVar($application, 'PORT', '4000', isPreview: true);

    $detectedPort = $application->detectPortFromEnvironment(true);

    expect($detectedPort)->toBe(4000);
});

it('verifies ports_exposes array conversion logic', function () {
    // Test the logic that converts comma-separated ports to array
    $portsExposesString = '3000,3001,8080';
    $expectedArray = [3000, 3001, 8080];

    // This simulates what portsExposesArray accessor does
    $result = is_null($portsExposesString)
        ? []
        : explode(',', $portsExposesString);

    // Convert to integers for comparison
    $result = array_map('intval', $result);

    expect($result)->toBe($expectedArray);
});

it('verifies PORT matches detection logic', function () {
    $detectedPort = 3000;
    $portsExposesArray = [3000, 3001];

    $isMatch = in_array($detectedPort, $portsExposesArray);

    expect($isMatch)->toBeTrue();
});

it('verifies PORT mismatch detection logic', function () {
    $detectedPort = 8080;
    $portsExposesArray = [3000, 3001];

    $isMatch = in_array($detectedPort, $portsExposesArray);

    expect($isMatch)->toBeFalse();
});

it('verifies empty ports_exposes detection logic', function () {
    $portsExposesArray = [];

    $isEmpty = empty($portsExposesArray);

    expect($isEmpty)->toBeTrue();
});
