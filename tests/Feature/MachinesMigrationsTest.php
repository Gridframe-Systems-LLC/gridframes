<?php

use App\Models\Machine;
use App\Models\MachineUsageSample;
use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// TestCase is bound to Feature tests in tests/Pest.php; this file adds the database trait.
uses(RefreshDatabase::class);
beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('rebuilds the schema with migrate:fresh and stores a machine', function () {
    // RefreshDatabase holds the test in a transaction, and a rebuild cannot run inside one.
    DB::connection()->commit();

    expect(Artisan::call('migrate:fresh'))->toBe(0);

    $team = Team::factory()->create();
    $machine = Machine::create([
        'uuid' => Str::uuid()->toString(),
        'team_id' => $team->id,
        'name' => 'northlatch-1',
        'size_key' => 'm-1',
        'vcpu' => 1,
        'memory_mb' => 1024,
        'disk_gb' => 10,
        'incus_project' => 'team-'.$team->id,
        'incus_name' => 'gf-'.$team->id.'-northlatch-1',
    ]);

    $this->assertDatabaseHas('machines', [
        'uuid' => $machine->uuid,
        'team_id' => $team->id,
        'name' => 'northlatch-1',
        'size_key' => 'm-1',
        'state' => 'creating',
    ]);

    $fresh = $machine->fresh();
    expect($fresh->size())->toBe(config('machines.sizes.m-1'));

    $fresh->delete();
    $this->assertSoftDeleted('machines', ['id' => $machine->id]);
});

it('stores a usage sample and stores a failed read as missing, never as zero', function () {
    $machine = Machine::create([
        'uuid' => Str::uuid()->toString(),
        'team_id' => $this->team->id,
        'name' => 'measured',
        'size_key' => 'm-2',
        'vcpu' => 1,
        'memory_mb' => 2048,
        'disk_gb' => 20,
        'incus_project' => 'team-'.$this->team->id,
        'incus_name' => 'gf-'.$this->team->id.'-measured',
    ]);

    MachineUsageSample::create([
        'machine_id' => $machine->id,
        'sampled_at' => now(),
        'memory_mb' => 512,
        'disk_mb' => 2048,
        'tx_bytes_total' => 123456789,
    ]);

    $failed = MachineUsageSample::create([
        'machine_id' => $machine->id,
        'sampled_at' => now(),
        'failure_kind' => 'timeout',
        'failure_detail' => 'The Incus API did not answer within 10 seconds',
    ]);

    $this->assertDatabaseHas('machine_usage_samples', [
        'machine_id' => $machine->id,
        'memory_mb' => 512,
        'disk_mb' => 2048,
        'tx_bytes_total' => 123456789,
        'failure_kind' => null,
    ]);

    $fresh = $failed->fresh();
    expect($fresh->memory_mb)->toBeNull()
        ->and($fresh->disk_mb)->toBeNull()
        ->and($fresh->tx_bytes_total)->toBeNull()
        ->and($fresh->failure_kind)->toBe('timeout')
        ->and($fresh->isFailure())->toBeTrue();
});

it('stores an overage charge and refuses a second one with the same charge_ref', function () {
    $chargeRef = 'team-'.$this->team->id.'-overage-2026-09';
    $row = [
        'team_id' => $this->team->id,
        'period' => '2026-09',
        'memory_cents' => 400,
        'disk_cents' => 8,
        'transfer_cents' => 5,
        'total_cents' => 413,
        'provider' => 'credits',
        'charge_ref' => $chargeRef,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('machine_overage_charges')->insert($row);
    $this->assertDatabaseHas('machine_overage_charges', ['charge_ref' => $chargeRef, 'total_cents' => 413]);

    expect(fn () => DB::table('machine_overage_charges')->insert($row))
        ->toThrow(UniqueConstraintViolationException::class);
});
