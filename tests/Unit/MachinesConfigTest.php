<?php

use Illuminate\Support\Env;

/*
 * The machines and subscription config must parse from the environment alone, so the keys they read
 * are cleared, the files re-required, and the environment restored, the way
 * DatabaseReadWriteHostConfigTest does for the database config.
 */
function machinesConfigWithEnvironment(array $overrides): array
{
    $keys = [
        'MACHINES_ENABLED',
        'MACHINES_INCUS_URL',
        'MACHINES_INCUS_CLIENT_CERT',
        'MACHINES_INCUS_CLIENT_KEY',
        'MACHINES_INCUS_SERVER_CERT',
        'MACHINES_INCUS_POOL',
        'MACHINES_IMAGE',
        'MACHINES_HOST_VCPU',
        'MACHINES_HOST_MEMORY_MB',
        'MACHINES_HOST_DISK_GB',
        'MACHINES_MEMORY_OVERCOMMIT',
        'MACHINES_DOMAIN',
        'MACHINES_SSH_HOST',
        'MACHINES_STOP_AFTER_GRACE_DAYS',
        'MACHINES_DELETE_AFTER_STOP_DAYS',
        'MACHINES_FINAL_SNAPSHOT_KEEP_DAYS',
    ];

    return configWithEnvironment($keys, $overrides, __DIR__.'/../../config/machines.php');
}

function subscriptionTiersWithEnvironment(array $overrides): array
{
    $keys = [];
    foreach (['AGENT_1GB', 'AGENT_2GB', 'PERSONAL', 'TEAM'] as $prefix) {
        foreach ([
            'POOL_VCPU',
            'POOL_MEMORY_MB',
            'POOL_DISK_GB',
            'POOL_TRANSFER_GB',
            'MAX_MACHINES',
            'OVERAGE_MEMORY_CENTS_PER_GB_MONTH',
            'OVERAGE_DISK_CENTS_PER_GB_MONTH',
            'OVERAGE_TRANSFER_CENTS_PER_GB',
            'PER_MEMBER',
        ] as $suffix) {
            $keys[] = "PLAN_{$prefix}_{$suffix}";
        }
    }

    return configWithEnvironment($keys, $overrides, __DIR__.'/../../config/subscription.php')['plans'];
}

function configWithEnvironment(array $keys, array $overrides, string $path): array
{
    $repository = Env::getRepository();
    $original = [];

    foreach ($keys as $key) {
        $original[$key] = env($key);
        $repository->clear($key);
    }

    try {
        foreach ($overrides as $key => $value) {
            $repository->set($key, (string) $value);
        }

        return require $path;
    } finally {
        foreach ($keys as $key) {
            $repository->clear($key);

            if ($original[$key] !== null) {
                $repository->set($key, (string) $original[$key]);
            }
        }
    }
}

it('parses each machine size with its vcpu, memory and disk', function (string $key, int $vcpu, int $memoryMb, int $diskGb) {
    $config = machinesConfigWithEnvironment([]);

    expect($config['sizes'][$key]['vcpu'])->toBe($vcpu)
        ->and($config['sizes'][$key]['memory_mb'])->toBe($memoryMb)
        ->and($config['sizes'][$key]['disk_gb'])->toBe($diskGb);
})->with([
    'smallest size is half a gigabyte' => ['m-0.5', 1, 512, 10],
    'one gigabyte size' => ['m-1', 1, 1024, 10],
    'two gigabyte size doubles the disk' => ['m-2', 1, 2048, 20],
    'four gigabyte size takes two vcpu' => ['m-4', 2, 4096, 40],
    'eight gigabyte size takes four vcpu' => ['m-8', 4, 8192, 80],
]);

it('is off by default and only a true MACHINES_ENABLED turns it on', function () {
    expect(machinesConfigWithEnvironment([])['enabled'])->toBeFalse()
        ->and(machinesConfigWithEnvironment(['MACHINES_ENABLED' => 'false'])['enabled'])->toBeFalse()
        ->and(machinesConfigWithEnvironment(['MACHINES_ENABLED' => 'true'])['enabled'])->toBeTrue();
});

it('defaults every new tier pool and overage key to zero and per_member to false', function () {
    $tiers = subscriptionTiersWithEnvironment([]);

    foreach ($tiers as $name => $tier) {
        foreach ([
            'pool_vcpu',
            'pool_memory_mb',
            'pool_disk_gb',
            'pool_transfer_gb',
            'max_machines',
            'overage_memory_cents_per_gb_month',
            'overage_disk_cents_per_gb_month',
            'overage_transfer_cents_per_gb',
        ] as $key) {
            expect($tier[$key])->toBe(0, "PLAN {$name} {$key} should default to zero");
        }

        expect($tier['per_member'])->toBeFalse("PLAN {$name} per_member should default to false");
    }
});

it('reads the tier pool from its plan prefix in the environment', function () {
    $tiers = subscriptionTiersWithEnvironment([
        'PLAN_PERSONAL_POOL_VCPU' => '2',
        'PLAN_PERSONAL_POOL_MEMORY_MB' => '8192',
        'PLAN_PERSONAL_MAX_MACHINES' => '50',
        'PLAN_PERSONAL_OVERAGE_DISK_CENTS_PER_GB_MONTH' => '8',
        'PLAN_TEAM_PER_MEMBER' => 'true',
    ]);

    expect($tiers['personal']['pool_vcpu'])->toBe(2)
        ->and($tiers['personal']['pool_memory_mb'])->toBe(8192)
        ->and($tiers['personal']['max_machines'])->toBe(50)
        ->and($tiers['personal']['overage_disk_cents_per_gb_month'])->toBe(8)
        ->and($tiers['team']['per_member'])->toBeTrue();
});
