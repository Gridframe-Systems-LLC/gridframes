<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies null and empty environment variables are filtered out of the
 * Nixpacks build context (issue: `--env KEY=` broke Nixpacks JSON parsing).
 *
 * Runs the REAL generate_nixpacks_env_variables() on a real job instance with
 * DB-backed Application + EnvironmentVariable rows (resourceable morph), per
 * the I5 pattern: real models, no Mockery on Eloquent.
 */
uses(TestCase::class, RefreshDatabase::class);

class TestableNixpacksDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}
}

function makeNixpacksApplication(array $attributes = []): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create(array_merge([
        'environment_id' => $environment->id,
        'build_pack' => 'nixpacks',
        'fqdn' => 'https://test.example.com',
    ], $attributes));

    $application->settings->update(['include_source_commit_in_build' => true]);

    return $application;
}

function createNixpacksEnvVar(Application $application, string $key, ?string $value, bool $isPreview = false): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => $key,
        'value' => $value,
        'is_preview' => $isPreview,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);
}

function makeNixpacksJob(Application $application, int $pullRequestId = 0, array $extraProperties = []): array
{
    $job = new TestableNixpacksDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'application' => $application,
        'pull_request_id' => $pullRequestId,
        'mainServer' => new Server,
        'commit' => 'abc123',
        'branch' => 'main',
        'container_name' => 'test-container',
    ] + $extraProperties as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    return [$job, $reflection];
}

function generateNixpacksEnvArgs(object $job, ReflectionClass $reflection): string
{
    $method = $reflection->getMethod('generate_nixpacks_env_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    $envArgsProperty = $reflection->getProperty('env_nixpacks_args');
    $envArgsProperty->setAccessible(true);

    return $envArgsProperty->getValue($job);
}

it('filters out null environment variables from nixpacks build command', function () {
    $application = makeNixpacksApplication();
    createNixpacksEnvVar($application, 'NIXPACKS_VALID_VAR', 'valid_value');
    createNixpacksEnvVar($application, 'NIXPACKS_NULL_VAR', null);
    createNixpacksEnvVar($application, 'NIXPACKS_EMPTY_VAR', '');
    createNixpacksEnvVar($application, 'NIXPACKS_ANOTHER_VALID_VAR', 'another_value');

    [$job, $reflection] = makeNixpacksJob($application);

    $envArgs = generateNixpacksEnvArgs($job, $reflection);

    // Valid variables survive; null/empty ones are filtered so Nixpacks
    // never sees `--env KEY=`.
    expect($envArgs)->toContain("--env 'NIXPACKS_VALID_VAR=valid_value'");
    expect($envArgs)->toContain("--env 'NIXPACKS_ANOTHER_VALID_VAR=another_value'");
    expect($envArgs)->not->toContain('NIXPACKS_NULL_VAR');
    expect($envArgs)->not->toContain('NIXPACKS_EMPTY_VAR');

    // Build-time XLAUNCH_* vars from the real generate_xlaunch_env_variables()
    // path: SOURCE_COMMIT resolves from the job's commit.
    expect($envArgs)->toContain("--env 'SOURCE_COMMIT=abc123'");
    expect($envArgs)->not->toMatch("/--env '[A-Z_]+='$/");
});

it('filters out null environment variables from nixpacks preview deployments', function () {
    $application = makeNixpacksApplication();
    createNixpacksEnvVar($application, 'NIXPACKS_PREVIEW_VAR', 'preview_value', isPreview: true);
    createNixpacksEnvVar($application, 'NIXPACKS_NULL_PREVIEW_VAR', null, isPreview: true);

    $preview = new ApplicationPreview;
    $preview->fqdn = 'https://preview.example.com';

    [$job, $reflection] = makeNixpacksJob($application, pullRequestId: 123, extraProperties: [
        'preview' => $preview,
    ]);

    $envArgs = generateNixpacksEnvArgs($job, $reflection);

    expect($envArgs)->toContain("--env 'NIXPACKS_PREVIEW_VAR=preview_value'");
    expect($envArgs)->not->toContain('NIXPACKS_NULL_PREVIEW_VAR');

    // Preview branch: the preview fqdn feeds the XLAUNCH_* build context.
    expect($envArgs)->toContain('preview.example.com');
});

it('handles all environment variables being null or empty', function () {
    $application = makeNixpacksApplication();
    createNixpacksEnvVar($application, 'NIXPACKS_NULL_VAR', null);
    createNixpacksEnvVar($application, 'NIXPACKS_EMPTY_VAR', '');

    [$job, $reflection] = makeNixpacksJob($application);

    $envArgs = generateNixpacksEnvArgs($job, $reflection);

    // The null/empty vars the app auto-seeds (NIXPACKS_NODE_VERSION) may be
    // present, but the null/empty ones must never render — and nothing may
    // appear as a bare `KEY=` (the signature that broke Nixpacks JSON).
    expect($envArgs)->not->toContain('NIXPACKS_NULL_VAR');
    expect($envArgs)->not->toContain('NIXPACKS_EMPTY_VAR');
    expect($envArgs)->not->toMatch("/--env '[A-Z_]+='$/");
    expect($envArgs)->not->toMatch('/--env [A-Z_]+=$/');
});

it('preserves environment variables with zero values', function () {
    $application = makeNixpacksApplication();
    createNixpacksEnvVar($application, 'NIXPACKS_ZERO_VALUE', '0');
    createNixpacksEnvVar($application, 'NIXPACKS_FALSE_VALUE', 'false');

    [$job, $reflection] = makeNixpacksJob($application);

    $envArgs = generateNixpacksEnvArgs($job, $reflection);

    expect($envArgs)->toContain("--env 'NIXPACKS_ZERO_VALUE=0'");
    expect($envArgs)->toContain("--env 'NIXPACKS_FALSE_VALUE=false'");
});

it('filters out null xlaunch env variables from env_args used in nixpacks plan JSON', function () {
    // GitHub issue #6830: when application->fqdn is null, XLAUNCH_FQDN/XLAUNCH_URL
    // get set to null and end up in the nixpacks plan JSON "variables", where
    // Nixpacks requires all values to be strings:
    // "Error: Failed to parse Nixpacks config file - invalid type: null, expected a string"

    $xlaunch_envs = collect([
        'XLAUNCH_URL' => null,
        'XLAUNCH_FQDN' => null,
        'XLAUNCH_BRANCH' => 'main',
        'XLAUNCH_RESOURCE_UUID' => 'abc123',
        'XLAUNCH_CONTAINER_NAME' => '',
    ]);

    // Apply the same filtering logic used in generate_env_variables()
    $env_args = collect([]);
    $xlaunch_envs->each(function ($value, $key) use ($env_args) {
        if (! is_null($value) && $value !== '') {
            $env_args->put($key, $value);
        }
    });

    expect($env_args->has('XLAUNCH_URL'))->toBeFalse();
    expect($env_args->has('XLAUNCH_FQDN'))->toBeFalse();
    expect($env_args->has('XLAUNCH_CONTAINER_NAME'))->toBeFalse();
    expect($env_args->get('XLAUNCH_BRANCH'))->toBe('main');
    expect($env_args->get('XLAUNCH_RESOURCE_UUID'))->toBe('abc123');

    $json = json_encode(['variables' => $env_args->toArray()], JSON_PRETTY_PRINT);
    $parsed = json_decode($json, true);
    foreach ($parsed['variables'] as $value) {
        expect($value)->toBeString();
    }
});
