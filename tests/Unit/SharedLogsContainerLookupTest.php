<?php

/**
 * Runtime Logs must query Docker with the label that matches the resource type.
 * Applications use xlaunch.applicationId, services xlaunch.serviceId, databases xlaunch.databaseId.
 * Using the application helper for services/databases returns empty results even when containers run.
 */
it('looks up containers with the correct helper for each resource type', function () {
    $logsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/Logs.php');

    expect($logsFile)
        ->toContain('getCurrentApplicationContainerStatus(')
        ->toContain('getCurrentServiceContainerStatus(')
        ->toContain('getCurrentDatabaseContainerStatus(')
        ->toContain('$this->resource instanceof Application')
        ->toContain('$this->resource instanceof Service');
});

it('filters application containers by xlaunch.applicationId', function () {
    $dockerHelpers = file_get_contents(__DIR__.'/../../bootstrap/helpers/docker.php');

    expect($dockerHelpers)->toContain('label=xlaunch.applicationId=');
});

it('filters service and database containers by the correct xlaunch labels', function () {
    $dockerHelpers = file_get_contents(__DIR__.'/../../bootstrap/helpers/docker.php');

    expect($dockerHelpers)
        ->toContain('function getCurrentServiceContainerStatus')
        ->toContain('label=xlaunch.serviceId=')
        ->toContain('function getCurrentDatabaseContainerStatus')
        ->toContain('label=xlaunch.databaseId=');
});
