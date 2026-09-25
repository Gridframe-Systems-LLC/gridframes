<?php

/*
 * GridFrames machines: virtual machines the platform sells and runs itself, on its own host, through
 * Incus. Everything here belongs to a deployment, so everything here comes from the environment. The
 * feature is off unless MACHINES_ENABLED is true, and with it off the platform behaves exactly as it
 * does today. A host capacity of zero means sell nothing, whatever the rest says.
 */
return [
    'enabled' => filter_var(env('MACHINES_ENABLED', false), FILTER_VALIDATE_BOOL),
    'incus' => [
        'url' => env('MACHINES_INCUS_URL'), // https://127.0.0.1:8443
        'client_cert' => env('MACHINES_INCUS_CLIENT_CERT'), // path
        'client_key' => env('MACHINES_INCUS_CLIENT_KEY'), // path
        'server_cert' => env('MACHINES_INCUS_SERVER_CERT'), // path, pinned
        'pool' => env('MACHINES_INCUS_POOL', 'gf-pool'),
        'image' => env('MACHINES_IMAGE', 'gf-ubuntu-24.04'),
    ],
    'host' => [
        'vcpu' => (int) env('MACHINES_HOST_VCPU', 0), // 0 = sell nothing
        'memory_mb' => (int) env('MACHINES_HOST_MEMORY_MB', 0),
        'disk_gb' => (int) env('MACHINES_HOST_DISK_GB', 0),
        'memory_overcommit' => (float) env('MACHINES_MEMORY_OVERCOMMIT', 1.0),
    ],
    // Sizes a customer can pick. Disk per size is the machine's root disk. The keys are the contract
    // with Incus limits: changing a size never resizes existing machines silently.
    'sizes' => [
        'm-0.5' => ['vcpu' => 1, 'memory_mb' => 512, 'disk_gb' => 10],
        'm-1' => ['vcpu' => 1, 'memory_mb' => 1024, 'disk_gb' => 10],
        'm-2' => ['vcpu' => 1, 'memory_mb' => 2048, 'disk_gb' => 20],
        'm-4' => ['vcpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 40],
        'm-8' => ['vcpu' => 4, 'memory_mb' => 8192, 'disk_gb' => 80],
    ],
    'domains' => [
        'machines' => env('MACHINES_DOMAIN'), // box.gridframes.app
        'ssh' => env('MACHINES_SSH_HOST'), // ssh.gridframes.app
    ],
    'lifecycle' => [
        // Days a machine keeps running after the plan's payment grace has passed, days it stays
        // stopped before deletion, and days the final snapshot survives the deletion.
        'stop_after_grace_days' => (int) env('MACHINES_STOP_AFTER_GRACE_DAYS', 3),
        'delete_after_stop_days' => (int) env('MACHINES_DELETE_AFTER_STOP_DAYS', 14),
        'final_snapshot_keep_days' => (int) env('MACHINES_FINAL_SNAPSHOT_KEEP_DAYS', 30),
    ],
];
