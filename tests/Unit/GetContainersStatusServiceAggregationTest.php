<?php

/**
 * Unit tests for GetContainersStatus service aggregation logic (SSH path).
 *
 * These tests verify that the SSH-based status updates (GetContainersStatus)
 * correctly aggregates container statuses for services with multiple containers,
 * using the same logic as PushServerUpdateJob (Sentinel path).
 *
 * This ensures consistency across both status update paths and prevents
 * race conditions where the last container processed wins.
 */
it('implements service multi-container aggregation in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify service container collection property exists
    expect($actionFile)
        ->toContain('protected ?Collection $serviceContainerStatuses;');

    // Verify aggregateServiceContainerStatuses method exists
    expect($actionFile)
        ->toContain('private function aggregateServiceContainerStatuses($services)')
        ->toContain('$this->aggregateServiceContainerStatuses($services);');

    // Verify service aggregation uses same logic as applications:
    // both paths delegate to the shared ContainerStatusAggregator state machine
    expect($actionFile)
        ->toContain('use App\Services\ContainerStatusAggregator;')
        ->toContain('$aggregator = new ContainerStatusAggregator;');
});

it('services use same priority as applications in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // The priority logic now lives in the shared ContainerStatusAggregator state machine
    // (colon-format statuses), so services and applications get identical priorities.
    $priorityLogic = <<<'PHP'
            if ($hasUnhealthy) {
                return 'running:unhealthy';
            } elseif ($hasUnknown) {
                return 'running:unknown';
            } else {
                return 'running:healthy';
            }
PHP;

    // Should appear in the shared aggregator both aggregation paths delegate to
    expect($aggregatorFile)->toContain($priorityLogic);

    // The SSH path should use the shared aggregator for its service statuses
    expect($actionFile)->toContain('$aggregator = new ContainerStatusAggregator;');
});

it('collects service containers before aggregating in SSH path', function () {
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Verify service containers are collected, not immediately updated
    expect($actionFile)
        ->toContain('$key = $serviceLabelId.\':\'.$subType.\':\'.$subId;')
        ->toContain('$this->serviceContainerStatuses->get($key)->put($containerName, $containerStatus);');

    // Verify aggregation happens before ServiceChecked dispatch
    expect($actionFile)
        ->toContain('$this->aggregateServiceContainerStatuses($services);')
        ->toContain('ServiceChecked::dispatch($this->server->team->id);');
});

it('SSH and Sentinel paths use identical service aggregation logic', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');
    $aggregatorFile = file_get_contents(__DIR__.'/../../app/Services/ContainerStatusAggregator.php');

    // Both paths should delegate to the same shared aggregator state machine
    expect($jobFile)->toContain('use App\Services\ContainerStatusAggregator;');
    expect($actionFile)->toContain('use App\Services\ContainerStatusAggregator;');
    expect($jobFile)->toContain('$aggregator = new ContainerStatusAggregator;');
    expect($actionFile)->toContain('$aggregator = new ContainerStatusAggregator;');

    // The shared aggregator should track the unknown status flag
    expect($aggregatorFile)->toContain('$hasUnknown = false;');
    expect($aggregatorFile)->toContain('if (str($status)->contains(\'unknown\')) {');

    // The aggregator should give unknown lower priority than unhealthy
    expect($aggregatorFile)->toContain('} elseif ($hasUnknown) {');
});

it('handles service status updates consistently', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $actionFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Both should parse service key with same format
    expect($jobFile)->toContain('[$serviceId, $subType, $subId] = explode(\':\', $key);');
    expect($actionFile)->toContain('[$serviceId, $subType, $subId] = explode(\':\', $key);');

    // Both should handle excluded containers via the shared CalculatesExcludedStatus trait
    expect($jobFile)->toContain('use App\Traits\CalculatesExcludedStatus;');
    expect($actionFile)->toContain('use App\Traits\CalculatesExcludedStatus;');
    expect($jobFile)->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
    expect($actionFile)->toContain('$excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);');
});
