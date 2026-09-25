<?php

use App\Models\MachineUsageSample;
use App\Services\Machines\TeamUsage;
use App\Services\Machines\UsageCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

const FROM = '2026-04-01 00:00:00';

const TO = '2026-05-01 00:00:00';

// The whole month of April 2026 in minutes: 30 days.
const MINUTES = 43200;

beforeEach(function () {
    $this->calculator = new UsageCalculator;
});

function anAllocation(int $memoryMb = 2048, int $diskGb = 20, ?Carbon $from = null, ?Carbon $to = null): array
{
    return [
        'from' => $from ?? Carbon::parse(FROM),
        'to' => $to ?? Carbon::parse(TO),
        'memory_mb' => $memoryMb,
        'disk_gb' => $diskGb,
    ];
}

/**
 * @param  array<int, int|null>  $counters  the cumulative transmit counter of each hour, null for a sample without a reading
 * @param  array<int, string>  $failures  hour index to failure kind
 */
function hourlySamples(array $counters, array $failures = []): array
{
    $samples = [];
    $at = Carbon::parse(FROM);
    foreach ($counters as $index => $counter) {
        $failure = $failures[$index] ?? null;
        $samples[] = new MachineUsageSample([
            'sampled_at' => $at->copy(),
            'tx_bytes_total' => $failure === null ? $counter : null,
            'failure_kind' => $failure,
            'failure_detail' => $failure ? 'the host did not answer' : null,
        ]);
        $at->addHour();
    }

    return $samples;
}

function gigabytes(int $count): int
{
    return $count * UsageCalculator::GB_BYTES;
}

test('a full month charges the reserved size for every hour and reads transfer from the counters', function () {
    $counters = range(1, 720);
    $usage = $this->calculator->machine(
        Carbon::parse(FROM),
        Carbon::parse(TO),
        [anAllocation()],
        hourlySamples(array_map(fn ($i) => gigabytes($i), $counters)),
    );

    expect($usage->measuredHours)->toBe(720)
        ->and($usage->failedHours)->toBe(0)
        ->and($usage->notMeasuredHours)->toBe(0)
        ->and($usage->memoryBytesMinutes)->toBe(2048 * UsageCalculator::MB_BYTES * MINUTES)
        ->and($usage->diskBytesMinutes)->toBe(gigabytes(20) * MINUTES)
        // The first counter is the base the month is read from, so 719 hours of transfer.
        ->and($usage->transferBytes)->toBe(gigabytes(719));
});

test('a machine that appeared mid-month is charged only for its own hours', function () {
    $from = Carbon::parse('2026-04-10 12:00:00');
    $samples = [];
    $at = $from->copy();
    while ($at->lt(Carbon::parse(TO))) {
        $samples[] = new MachineUsageSample(['sampled_at' => $at->copy(), 'tx_bytes_total' => 0]);
        $at->addHour();
    }

    $usage = $this->calculator->machine($from, Carbon::parse(TO), [anAllocation(from: $from)], $samples);

    // April 10 12:00 to May 1: twenty and a half days.
    $expectedMinutes = (20 * 24 + 12) * 60;
    expect($usage->measuredHours)->toBe(492)
        ->and($usage->memoryBytesMinutes)->toBe(2048 * UsageCalculator::MB_BYTES * $expectedMinutes)
        ->and($usage->transferBytes)->toBe(0);
});

test('a mid-month resize bills each hour at the size it was reserved for', function () {
    $resizeAt = Carbon::parse('2026-04-15 12:00:00');
    $allocations = [
        anAllocation(memoryMb: 1024, diskGb: 10, to: $resizeAt),
        anAllocation(memoryMb: 4096, diskGb: 40, from: $resizeAt),
    ];
    $samples = hourlySamples(array_fill(0, 720, 0));

    $usage = $this->calculator->machine(Carbon::parse(FROM), Carbon::parse(TO), $allocations, $samples);

    $beforeMinutes = (14 * 24 + 12) * 60;
    $afterMinutes = MINUTES - $beforeMinutes;
    expect($usage->measuredHours)->toBe(720)
        ->and($usage->memoryBytesMinutes)->toBe(1024 * UsageCalculator::MB_BYTES * $beforeMinutes + 4096 * UsageCalculator::MB_BYTES * $afterMinutes)
        ->and($usage->diskBytesMinutes)->toBe(gigabytes(10) * $beforeMinutes + gigabytes(40) * $afterMinutes);
});

test('a counter reset starts a new segment and never turns into negative transfer', function () {
    $usage = $this->calculator->machine(
        Carbon::parse(FROM),
        Carbon::parse(FROM)->addHours(4),
        [anAllocation()],
        hourlySamples([gigabytes(100), gigabytes(150), gigabytes(10), gigabytes(40)]),
    );

    // 50 GB across the first pair, the reset adds nothing, then 30 GB from the new segment.
    expect($usage->transferBytes)->toBe(gigabytes(80));
});

test('a failed sample is a reported gap whose hour is never charged', function () {
    $counters = array_map(fn ($i) => gigabytes($i), range(1, 720));
    $usage = $this->calculator->machine(
        Carbon::parse(FROM),
        Carbon::parse(TO),
        [anAllocation()],
        hourlySamples($counters, [167 => 'timeout']),
    );

    expect($usage->measuredHours)->toBe(719)
        ->and($usage->failedHours)->toBe(1)
        ->and($usage->notMeasuredHours)->toBe(1)
        ->and($usage->memoryBytesMinutes)->toBe(2048 * UsageCalculator::MB_BYTES * (MINUTES - 60))
        // The counters either side of the gap are still consecutive readings of one cumulative
        // counter, so the bridged step carries the transfer of both hours; a reset inside the gap
        // would come out negative and add nothing instead.
        ->and($usage->transferBytes)->toBe(gigabytes(719));
});

test('an hour with no sample at all is not measured either', function () {
    $samples = hourlySamples(array_map(fn ($i) => gigabytes($i), range(1, 700)));
    $usage = $this->calculator->machine(Carbon::parse(FROM), Carbon::parse(TO), [anAllocation()], $samples);

    expect($usage->measuredHours)->toBe(700)
        ->and($usage->failedHours)->toBe(0)
        ->and($usage->notMeasuredHours)->toBe(20)
        ->and($usage->memoryBytesMinutes)->toBe(2048 * UsageCalculator::MB_BYTES * 700 * 60);
});

test('a month exactly at the pool owes nothing', function () {
    $plan = [
        'pool_memory_bytes' => 2048 * UsageCalculator::MB_BYTES,
        'pool_disk_bytes' => gigabytes(20),
        'pool_transfer_bytes' => gigabytes(719),
        'memory_rate_cents' => 400,
        'disk_rate_cents' => 8,
        'transfer_rate_cents' => 5,
    ];
    $usage = $this->calculator->machine(
        Carbon::parse(FROM),
        Carbon::parse(TO),
        [anAllocation()],
        hourlySamples(array_map(fn ($i) => gigabytes($i), range(1, 720))),
    );

    $overage = $this->calculator->overageCents(new TeamUsage(
        measuredHours: $usage->measuredHours,
        failedHours: $usage->failedHours,
        notMeasuredHours: $usage->notMeasuredHours,
        memoryBytesMinutes: $usage->memoryBytesMinutes,
        diskBytesMinutes: $usage->diskBytesMinutes,
        transferBytes: $usage->transferBytes,
        windowMinutes: MINUTES,
        monthMinutes: MINUTES,
        machines: [],
    ), $plan);

    expect($overage)->toBe([
        'memory_cents' => 0,
        'disk_cents' => 0,
        'transfer_cents' => 0,
        'total_cents' => 0,
        'memory_gb' => 0,
        'disk_gb' => 0,
        'transfer_gb' => 0,
    ]);
});

test('one unit over the pool bills the started GB at the plan rate', function () {
    $plan = [
        'pool_memory_bytes' => 2048 * UsageCalculator::MB_BYTES,
        'pool_disk_bytes' => gigabytes(20),
        'pool_transfer_bytes' => gigabytes(719),
        'memory_rate_cents' => 400,
        'disk_rate_cents' => 8,
        'transfer_rate_cents' => 5,
    ];
    $usage = $this->calculator->machine(
        Carbon::parse(FROM),
        Carbon::parse(TO),
        [anAllocation(memoryMb: 2049, diskGb: 21)],
        hourlySamples(array_map(fn ($i) => gigabytes($i), range(1, 720))),
    );
    $teamUsage = new TeamUsage(
        measuredHours: $usage->measuredHours,
        failedHours: $usage->failedHours,
        notMeasuredHours: $usage->notMeasuredHours,
        memoryBytesMinutes: $usage->memoryBytesMinutes,
        diskBytesMinutes: $usage->diskBytesMinutes,
        transferBytes: $usage->transferBytes + 1, // one byte over the pool
        windowMinutes: MINUTES,
        monthMinutes: MINUTES,
        machines: [],
    );

    $overage = $this->calculator->overageCents($teamUsage, $plan);

    expect($overage['memory_gb'])->toBe(1)
        ->and($overage['memory_cents'])->toBe(400)
        ->and($overage['disk_gb'])->toBe(1)
        ->and($overage['disk_cents'])->toBe(8)
        ->and($overage['transfer_gb'])->toBe(1)
        ->and($overage['transfer_cents'])->toBe(5)
        ->and($overage['total_cents'])->toBe(413);
});

test('a plan with its rates at zero is overage off, whatever it used', function () {
    $plan = [
        'pool_memory_bytes' => 0,
        'pool_disk_bytes' => 0,
        'pool_transfer_bytes' => 0,
        'memory_rate_cents' => 0,
        'disk_rate_cents' => 0,
        'transfer_rate_cents' => 0,
    ];
    $usage = $this->calculator->machine(Carbon::parse(FROM), Carbon::parse(TO), [anAllocation()], hourlySamples(array_fill(0, 720, 42)));
    $teamUsage = new TeamUsage(
        measuredHours: $usage->measuredHours,
        failedHours: $usage->failedHours,
        notMeasuredHours: $usage->notMeasuredHours,
        memoryBytesMinutes: $usage->memoryBytesMinutes,
        diskBytesMinutes: $usage->diskBytesMinutes,
        transferBytes: $usage->transferBytes,
        windowMinutes: MINUTES,
        monthMinutes: MINUTES,
        machines: [],
    );

    expect($this->calculator->overageCents($teamUsage, $plan)['total_cents'])->toBe(0);
});

test('a partial window shares the transfer pool by its share of the month', function () {
    $plan = [
        'pool_memory_bytes' => 0,
        'pool_disk_bytes' => 0,
        'pool_transfer_bytes' => gigabytes(100),
        'memory_rate_cents' => 0,
        'disk_rate_cents' => 0,
        'transfer_rate_cents' => 5,
    ];
    $usage = $this->calculator->machine(Carbon::parse(FROM), Carbon::parse(TO), [anAllocation()], hourlySamples(array_fill(0, 720, 0)));
    $teamUsage = new TeamUsage(
        measuredHours: 0,
        failedHours: 0,
        notMeasuredHours: 0,
        memoryBytesMinutes: 0,
        diskBytesMinutes: 0,
        transferBytes: gigabytes(60), // past the half-month share of the pool (50 GB)
        windowMinutes: MINUTES / 2,
        monthMinutes: MINUTES,
        machines: [],
    );

    $overage = $this->calculator->overageCents($teamUsage, $plan);

    expect($overage['transfer_gb'])->toBe(10)
        ->and($overage['transfer_cents'])->toBe(50);
});
