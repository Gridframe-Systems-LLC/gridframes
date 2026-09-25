@php
    use App\Models\MachineOverageCharge;
    use App\Services\Machines\UsageCalculator;

    $team = currentTeam();
    $calculator = app(UsageCalculator::class);
    $machinesPool = config('machines.enabled') && $team->machines()->exists() ? $calculator->plan($team) : null;
    $machinesUsage = $machinesPool && $machinesPool['has_pool']
        ? $calculator->team($team, now()->startOfMonth(), now())
        : null;
    $machinesOverage = $machinesUsage && $machinesPool['overage_enabled']
        ? $calculator->overageCents($machinesUsage, $machinesPool)
        : null;
    $machinesCharges = $machinesPool
        ? MachineOverageCharge::where('team_id', $team->id)->orderByDesc('period')->limit(6)->get()
        : collect();
    $memoryGbUsed = fn () => $machinesUsage->averageMemoryBytes($machinesUsage->windowMinutes) / UsageCalculator::GB_BYTES;
    $diskGbUsed = fn () => $machinesUsage->averageDiskBytes($machinesUsage->windowMinutes) / UsageCalculator::GB_BYTES;
    $transferGbUsed = fn () => $machinesUsage->transferBytes / UsageCalculator::GB_BYTES;
@endphp

@if ($machinesUsage)
    <x-application.settings-section title="Machines this month"
        description="What your machines have used of the plan's pool, measured from hourly readings. Hours without a good reading are not measured, and are never charged.">
        <div class="divide-y divide-neutral-200 text-sm dark:divide-white/[0.07]">
            <div class="flex items-center justify-between gap-4 py-2">
                <span>Memory reserved</span>
                <span>{{ number_format($memoryGbUsed(), 1) }} GB of {{ number_format($machinesPool['pool_memory_bytes'] / UsageCalculator::GB_BYTES, 0) }} GB
                    @if (! $machinesPool['overage_enabled'])
                        · overage off
                    @endif
                </span>
            </div>
            <div class="flex items-center justify-between gap-4 py-2">
                <span>Disk reserved</span>
                <span>{{ number_format($diskGbUsed(), 1) }} GB of {{ number_format($machinesPool['pool_disk_bytes'] / UsageCalculator::GB_BYTES, 0) }} GB</span>
            </div>
            <div class="flex items-center justify-between gap-4 py-2">
                <span>Transfer</span>
                <span>{{ number_format($transferGbUsed(), 1) }} GB of {{ number_format($machinesPool['pool_transfer_bytes'] / UsageCalculator::GB_BYTES, 0) }} GB</span>
            </div>
            <div class="flex items-center justify-between gap-4 py-2">
                <span>Hours measured</span>
                <span>{{ number_format($machinesUsage->measuredHours, 0) }}
                    @if ($machinesUsage->notMeasuredHours > 0)
                        · <span class="text-neutral-500">{{ number_format($machinesUsage->notMeasuredHours, 0) }} hours not measured</span>
                    @endif
                </span>
            </div>
            @if ($machinesOverage && $machinesOverage['total_cents'] > 0)
                <div class="flex items-center justify-between gap-4 py-2">
                    <span>Overage so far
                        <span class="text-neutral-500">(memory {{ number_format($machinesOverage['memory_gb'], 0) }} GB, disk {{ number_format($machinesOverage['disk_gb'], 0) }} GB, transfer {{ number_format($machinesOverage['transfer_gb'], 0) }} GB)</span>
                    </span>
                    <span>${{ number_format($machinesOverage['total_cents'] / 100, 2) }}
                        <span class="text-neutral-500">· charged after the month closes</span></span>
                </div>
            @endif
        </div>
    </x-application.settings-section>
@endif

@if ($machinesCharges->isNotEmpty())
    <x-application.settings-section title="Machine overage charges"
        description="The overage taken for each closed month, on top of the plan.">
        <div class="divide-y divide-neutral-200 text-sm dark:divide-white/[0.07]">
            @foreach ($machinesCharges as $charge)
                <div class="flex items-center justify-between gap-4 py-2">
                    <span>{{ $charge->period }}</span>
                    <span class="{{ $charge->status === 'paid' ? '' : 'text-red-500' }}">
                        ${{ number_format($charge->total_cents / 100, 2) }} ·
                        {{ ['paid' => 'paid', 'pending' => 'pending', 'failed' => 'not paid'][$charge->status] ?? $charge->status }}
                    </span>
                </div>
            @endforeach
        </div>
    </x-application.settings-section>
@endif
