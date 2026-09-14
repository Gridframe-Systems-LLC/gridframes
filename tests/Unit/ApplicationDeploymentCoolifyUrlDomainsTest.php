<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

class TestableXlaunchUrlDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}

    public function execute_remote_command(...$commands): void {}
}

function xlaunchVariablesForFqdn(string $fqdn, string $composeParsingVersion = '3'): string
{
    $team = Team::create([
        'name' => 'Xlaunch Url Team',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $project = Project::create([
        'name' => 'Xlaunch Url Project',
        'team_id' => $team->id,
    ]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'fqdn' => $fqdn,
    ]);

    // The created hook resets this, so it has to be set afterwards.
    $application->compose_parsing_version = $composeParsingVersion;
    $application->save();
    Application::withoutGlobalScopes()->whereKey($application->id)->update(['fqdn' => $fqdn]);

    $job = new TestableXlaunchUrlDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'application' => $application->fresh(),
        'pull_request_id' => 0,
        'commit' => 'HEAD',
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    $reflection->getMethod('set_xlaunch_variables')->invoke($job);

    return $reflection->getProperty('xlaunch_variables')->getValue($job);
}

it('keeps every domain intact when an application has multiple domains', function () {
    $variables = xlaunchVariablesForFqdn('https://a.example.com,https://b.example.com');

    expect($variables)
        ->toContain("XLAUNCH_URL='https://a.example.com,https://b.example.com'")
        ->toContain("XLAUNCH_FQDN='a.example.com,b.example.com'");
});

it('strips the port from every domain when an application has multiple domains', function () {
    $variables = xlaunchVariablesForFqdn('https://a.example.com:8080,https://b.example.com:9000');

    expect($variables)
        ->toContain("XLAUNCH_URL='https://a.example.com,https://b.example.com'")
        ->toContain("XLAUNCH_FQDN='a.example.com,b.example.com'");
});

it('keeps every domain intact on the legacy compose parsing version', function () {
    $variables = xlaunchVariablesForFqdn('https://a.example.com,https://b.example.com', '2');

    expect($variables)
        ->toContain("XLAUNCH_URL='a.example.com,b.example.com'")
        ->toContain("XLAUNCH_FQDN='https://a.example.com,https://b.example.com'");
});

it('still resolves a single domain', function () {
    $variables = xlaunchVariablesForFqdn('https://a.example.com');

    expect($variables)
        ->toContain("XLAUNCH_URL='https://a.example.com'")
        ->toContain("XLAUNCH_FQDN='a.example.com'");
});

it('ignores a hostless stored domain while setting deployment variables', function () {
    $variables = xlaunchVariablesForFqdn('https://,https://a.example.com');

    expect($variables)
        ->toContain("XLAUNCH_URL='https://a.example.com'")
        ->toContain("XLAUNCH_FQDN='a.example.com'");
});

it('does not set domain variables for a hostless stored fqdn', function () {
    $variables = xlaunchVariablesForFqdn('https://');

    expect($variables)
        ->not->toContain('XLAUNCH_URL=')
        ->not->toContain('XLAUNCH_FQDN=');
});
