<?php

namespace App\Services\Machines;

use App\Models\MachineUsageSample;
use App\Models\Team;
use Illuminate\Support\Carbon;

/**
 * Turns a window of hourly samples into what a team used and what of it is overage. Pure on purpose:
 * every number comes from the samples and the plan's pool, nothing is fetched, and every value is an
 * integer. A failed reading is never a value: an hour without a good sample is counted, reported,
 * and never charged, so an unmeasured hour can never look like a quiet machine.
 *
 * The arithmetic, all in integers:
 * - Memory and disk are billed as reserved size over time, in byte-minutes. Each measured hour adds
 *   the machine's allocated bytes (what the customer reserved, never the in-use reading) times the
 *   minutes of that hour the allocation covered, which is what makes a mid-month resize bill each
 *   hour at its own size.
 * - Transfer is the difference between consecutive cumulative transmit counters, read across a
 *   failed sample the same way as across a good one, because a cumulative counter is still
 *   cumulative after an outage. A counter that went backwards (a reboot reset it) starts a new
 *   segment: the step that notices the reset adds nothing, so a month's transfer is never negative.
 * - The plan's pool is an allowance over the window: pool bytes times the window's minutes, which
 *   for a whole calendar month is exactly the pool. Transfer, being a volume rather than a rate,
 *   shares the pool across the window's share of its month. Only the remainder is overage.
 * - Overage rounds up to the started GB: one byte over the pool bills the whole GB(-month).
 */
class UsageCalculator
{
    public const MB_BYTES = 1024 * 1024;

    public const GB_BYTES = 1024 * 1024 * 1024;

    /**
     * One machine's usage over a window.
     *
     * @param  array<int, array{from: Carbon, to: Carbon, memory_mb: int, disk_gb: int}>  $allocations
     *                                                                                                  The sizes the machine was reserved at, each over the stretch it held them.
     * @param  iterable<MachineUsageSample>  $samples  This machine's samples, any month.
     */
    public function machine(Carbon $from, Carbon $to, array $allocations, iterable $samples): MachineUsage
    {
        $successBuckets = [];
        $failedBuckets = [];
        $counterReadings = [];
        foreach ($samples as $sample) {
            if ($sample->sampled_at === null) {
                continue;
            }
            $bucket = $sample->sampled_at->copy()->startOfHour();
            if ($bucket->lt($from) || $bucket->gte($to)) {
                continue;
            }
            $key = $bucket->format('Y-m-d H');
            if ($sample->isFailure()) {
                $failedBuckets[$key] = $bucket;

                continue;
            }
            $successBuckets[$key] = $bucket;
            $counterReadings[] = ['at' => $sample->sampled_at, 'total' => $sample->tx_bytes_total];
        }
        usort($counterReadings, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        $transferBytes = 0;
        $previousTotal = null;
        foreach ($counterReadings as $reading) {
            $total = $reading['total'];
            if ($total === null) {
                // A reading without its counter: the transfer it would have covered stays unknown,
                // and the next good counter is compared against the last one that was known.
                continue;
            }
            if ($previousTotal !== null && $total > $previousTotal) {
                $transferBytes += $total - $previousTotal;
            }
            $previousTotal = $total;
        }

        $memoryBytesMinutes = 0;
        $diskBytesMinutes = 0;
        foreach ($successBuckets as $bucket) {
            $bucketEnd = $bucket->copy()->addHour();
            foreach ($allocations as $allocation) {
                $start = $bucket->lt($allocation['from']) ? $allocation['from'] : $bucket;
                $end = $bucketEnd->gt($allocation['to']) ? $allocation['to'] : $bucketEnd;
                if ($end->lte($start)) {
                    continue;
                }
                $minutes = (int) round($start->diffInMinutes($end));
                $memoryBytesMinutes += $allocation['memory_mb'] * self::MB_BYTES * $minutes;
                $diskBytesMinutes += $allocation['disk_gb'] * self::GB_BYTES * $minutes;
            }
        }

        $measuredHours = count($successBuckets);
        $failedHours = count(array_diff_key($failedBuckets, $successBuckets));
        $windowHours = 0;
        for ($bucket = $from->copy()->startOfHour(); $bucket->lt($to); $bucket->addHour()) {
            $windowHours++;
        }

        return new MachineUsage(
            measuredHours: $measuredHours,
            failedHours: $failedHours,
            notMeasuredHours: max(0, $windowHours - $measuredHours),
            memoryBytesMinutes: $memoryBytesMinutes,
            diskBytesMinutes: $diskBytesMinutes,
            transferBytes: $transferBytes,
        );
    }

    /**
     * A team's usage over a window, machine by machine. A machine is charged from when it existed,
     * at the size its row holds; its samples before the window or after the month are ignored.
     */
    public function team(Team $team, Carbon $from, Carbon $to): TeamUsage
    {
        $perMachine = [];
        $memoryBytesMinutes = 0;
        $diskBytesMinutes = 0;
        $transferBytes = 0;
        $measuredHours = 0;
        $failedHours = 0;
        $notMeasuredHours = 0;
        foreach ($team->machines()->with('usageSamples')->get() as $machine) {
            $machineFrom = $machine->created_at->gt($from) ? $machine->created_at->copy() : $from->copy();
            $usage = $this->machine($machineFrom, $to->copy(), [
                ['from' => $machineFrom, 'to' => $to->copy(), 'memory_mb' => $machine->memory_mb, 'disk_gb' => $machine->disk_gb],
            ], $machine->usageSamples);
            $perMachine[$machine->name] = $usage;
            $memoryBytesMinutes += $usage->memoryBytesMinutes;
            $diskBytesMinutes += $usage->diskBytesMinutes;
            $transferBytes += $usage->transferBytes;
            $measuredHours += $usage->measuredHours;
            $failedHours += $usage->failedHours;
            $notMeasuredHours += $usage->notMeasuredHours;
        }

        return new TeamUsage(
            measuredHours: $measuredHours,
            failedHours: $failedHours,
            notMeasuredHours: $notMeasuredHours,
            memoryBytesMinutes: $memoryBytesMinutes,
            diskBytesMinutes: $diskBytesMinutes,
            transferBytes: $transferBytes,
            windowMinutes: max(1, (int) round($from->diffInMinutes($to))),
            monthMinutes: max(1, $from->daysInMonth * 24 * 60),
            machines: $perMachine,
        );
    }

    /**
     * The team's plan pool and overage rates, in bytes, with a per-member pool multiplied by the
     * members it has. A plan without a pool or without rates says so in `has_pool` and
     * `overage_enabled`; everything else in the platform keys off the same zeros.
     *
     * @return array{has_pool: bool, overage_enabled: bool, pool_memory_bytes: int, pool_disk_bytes: int, pool_transfer_bytes: int, memory_rate_cents: int, disk_rate_cents: int, transfer_rate_cents: int}
     */
    public function plan(Team $team): array
    {
        $planKey = $team->subscription?->planKey();
        $plan = $planKey ? config("subscription.plans.{$planKey}") : null;
        $perMember = (bool) data_get($plan, 'per_member', false);
        $members = $perMember ? max(1, $team->members()->count()) : 1;

        $poolMemoryMb = (int) data_get($plan, 'pool_memory_mb', 0);
        $poolDiskGb = (int) data_get($plan, 'pool_disk_gb', 0);
        $poolTransferGb = (int) data_get($plan, 'pool_transfer_gb', 0);
        $memoryRate = (int) data_get($plan, 'overage_memory_cents_per_gb_month', 0);
        $diskRate = (int) data_get($plan, 'overage_disk_cents_per_gb_month', 0);
        $transferRate = (int) data_get($plan, 'overage_transfer_cents_per_gb', 0);

        return [
            'has_pool' => ($poolMemoryMb + $poolDiskGb + $poolTransferGb) > 0,
            'overage_enabled' => ($memoryRate + $diskRate + $transferRate) > 0,
            'pool_memory_bytes' => $poolMemoryMb * self::MB_BYTES * $members,
            'pool_disk_bytes' => $poolDiskGb * self::GB_BYTES * $members,
            'pool_transfer_bytes' => $poolTransferGb * self::GB_BYTES * $members,
            'memory_rate_cents' => $memoryRate,
            'disk_rate_cents' => $diskRate,
            'transfer_rate_cents' => $transferRate,
        ];
    }

    /**
     * The month's overage in cents, what remains of the pool subtracted and the rest rounded up to
     * the started GB(-month). A plan with overage off is charged nothing, whatever it used.
     *
     * @param  array{pool_memory_bytes: int, pool_disk_bytes: int, pool_transfer_bytes: int, memory_rate_cents: int, disk_rate_cents: int, transfer_rate_cents: int}  $plan
     * @return array{memory_cents: int, disk_cents: int, transfer_cents: int, total_cents: int, memory_gb: int, disk_gb: int, transfer_gb: int}
     */
    public function overageCents(TeamUsage $usage, array $plan): array
    {
        $memoryOverBytesMinutes = max(0, $usage->memoryBytesMinutes - $plan['pool_memory_bytes'] * $usage->windowMinutes);
        $memoryGb = $this->ceilDivide($memoryOverBytesMinutes, self::GB_BYTES * $usage->windowMinutes);
        $diskOverBytesMinutes = max(0, $usage->diskBytesMinutes - $plan['pool_disk_bytes'] * $usage->windowMinutes);
        $diskGb = $this->ceilDivide($diskOverBytesMinutes, self::GB_BYTES * $usage->windowMinutes);

        // Transfer's pool is a volume, shared across the window's share of its month.
        $transferPoolBytes = (int) ($plan['pool_transfer_bytes'] * $usage->windowMinutes / $usage->monthMinutes);
        $transferOverBytes = max(0, $usage->transferBytes - $transferPoolBytes);
        $transferGb = $this->ceilDivide($transferOverBytes, self::GB_BYTES);

        $memoryCents = $memoryGb * $plan['memory_rate_cents'];
        $diskCents = $diskGb * $plan['disk_rate_cents'];
        $transferCents = $transferGb * $plan['transfer_rate_cents'];

        return [
            'memory_cents' => $memoryCents,
            'disk_cents' => $diskCents,
            'transfer_cents' => $transferCents,
            'total_cents' => $memoryCents + $diskCents + $transferCents,
            'memory_gb' => $memoryGb,
            'disk_gb' => $diskGb,
            'transfer_gb' => $transferGb,
        ];
    }

    private function ceilDivide(int $value, int $unit): int
    {
        if ($value <= 0 || $unit <= 0) {
            return 0;
        }

        return intdiv($value, $unit) + ($value % $unit > 0 ? 1 : 0);
    }
}

/**
 * One machine's measured month: what was charged is what was measured, and the hours nobody
 * measured are counted, never guessed.
 */
final class MachineUsage
{
    public function __construct(
        public readonly int $measuredHours,
        public readonly int $failedHours,
        public readonly int $notMeasuredHours,
        public readonly int $memoryBytesMinutes,
        public readonly int $diskBytesMinutes,
        public readonly int $transferBytes,
    ) {}

    /**
     * The reserved memory averaged over the window, in bytes: the GB-month figure the pool is
     * compared against and the overage is priced from.
     */
    public function averageMemoryBytes(int $windowMinutes): int
    {
        return $windowMinutes > 0 ? (int) ($this->memoryBytesMinutes / $windowMinutes) : 0;
    }

    public function averageDiskBytes(int $windowMinutes): int
    {
        return $windowMinutes > 0 ? (int) ($this->diskBytesMinutes / $windowMinutes) : 0;
    }
}

/** A team's month across every machine, with the window it covers for pool subtraction. */
final class TeamUsage
{
    /**
     * @param  array<string, MachineUsage>  $machines  keyed by machine name
     */
    public function __construct(
        public readonly int $measuredHours,
        public readonly int $failedHours,
        public readonly int $notMeasuredHours,
        public readonly int $memoryBytesMinutes,
        public readonly int $diskBytesMinutes,
        public readonly int $transferBytes,
        public readonly int $windowMinutes,
        public readonly int $monthMinutes,
        public readonly array $machines,
    ) {}
}
