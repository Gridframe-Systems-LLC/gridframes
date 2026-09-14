<?php

use App\Jobs\CleanupHelperContainersJob;
use App\Models\Server;
use Symfony\Component\Process\Process;

it('matches helper image references without matching similarly named images', function () {
    $command = (new ReflectionMethod(CleanupHelperContainersJob::class, 'helperContainersCommand'))->invoke(null);
    $directory = sys_get_temp_dir().'/xlaunch-helper-filter-'.bin2hex(random_bytes(4));
    $docker = $directory.'/docker';
    $images = [
        'northlatch/xlaunch-helper:1.0.15',
        'docker.io/northlatch/xlaunch-helper:1.0.16',
        'ghcr.io/northlatch/xlaunch-helper@sha256:abc',
        'registry.example/team/northlatch/xlaunch-helper:latest',
        'northlatch/xlaunch:latest',
        'northlatch/xlaunch:4.3.12',
        'northlatch/xlaunch-realtime:1.0.10',
        'xlaunch-helper:latest',
        'someone/xlaunch-helper:1.0.16',
        'evil/northlatch/xlaunch-helper-copy:latest',
        'northlatch/not-xlaunch-helper:latest',
    ];

    mkdir($directory);
    file_put_contents($docker, "#!/bin/sh\n".implode("\n", array_map(
        fn (string $image): string => 'echo '.escapeshellarg(json_encode(['Image' => $image], JSON_THROW_ON_ERROR)),
        $images
    ))."\n");
    chmod($docker, 0755);

    try {
        $process = new Process(['/bin/sh', '-c', $command], env: [
            'PATH' => $directory.':'.getenv('PATH'),
        ]);
        $process->mustRun();

        expect(array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'Image'))
            ->toBe(array_slice($images, 0, 4));
    } finally {
        unlink($docker);
        rmdir($directory);
    }
});

it('preserves the helper image filter for non-root servers', function () {
    $command = (new ReflectionMethod(CleanupHelperContainersJob::class, 'helperContainersCommand'))->invoke(null);
    $server = Mockery::mock(Server::class)->makePartial();
    $server->user = 'ubuntu';
    $command = parseCommandsByLineForSudo(collect([$command]), $server)[0];
    $directory = sys_get_temp_dir().'/xlaunch-helper-sudo-filter-'.bin2hex(random_bytes(4));
    $docker = $directory.'/docker';
    $sudo = $directory.'/sudo';

    mkdir($directory);
    file_put_contents($docker, "#!/bin/sh\necho '{\"Image\":\"northlatch/xlaunch-helper:1.0.15\"}'\n");
    file_put_contents($sudo, "#!/bin/sh\nexec \"\$@\"\n");
    chmod($docker, 0755);
    chmod($sudo, 0755);

    try {
        $process = new Process(['/bin/sh', '-c', $command], env: [
            'PATH' => $directory.':'.getenv('PATH'),
        ]);
        $process->mustRun();

        expect(array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'Image'))
            ->toBe(['northlatch/xlaunch-helper:1.0.15']);
    } finally {
        unlink($docker);
        unlink($sudo);
        rmdir($directory);
    }
});
