<?php

namespace App\Jobs\Machines;

use App\Exceptions\MachineException;
use App\Models\Machine;
use App\Models\MachineUsageSample;
use App\Services\Machines\IncusClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads every running machine once an hour and writes what it saw: memory in use, disk in use and
 * the cumulative network transmit counter. A reading that fails is stored as missing with its
 * reason, never as zero, so an hour nobody measured can never be mistaken for a quiet machine.
 */
class SampleMachineUsageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Most machines one run reads. More waiting is logged as a partial run and picked up next hour. */
    public const BATCH = 1000;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(IncusClient $incus): void
    {
        $machines = Machine::query()
            ->where('state', 'running')
            ->orderBy('id');
        $pending = (clone $machines)->count();
        if ($pending > self::BATCH) {
            Log::warning('Machine sampling: more running machines than one run reads, so this run is partial. The rest are read next hour.', [
                'pending' => $pending,
                'batch' => self::BATCH,
            ]);
        }

        foreach ($machines->limit(self::BATCH)->get() as $machine) {
            $this->sample($incus, $machine);
        }
    }

    private function sample(IncusClient $incus, Machine $machine): void
    {
        try {
            $state = $incus->state($machine->incus_name);
        } catch (MachineException $e) {
            $this->storeFailure($machine->id, self::failureKind($e->kind), $e->getMessage());

            return;
        } catch (Throwable $e) {
            // An answer nobody expected is still not a reading: store it as missing and let the next
            // hour try again.
            $this->storeFailure($machine->id, 'malformed', $e->getMessage());

            return;
        }

        $counters = collect($state['network'])
            ->pluck('bytes_sent')
            ->filter(fn ($bytes) => $bytes !== null);
        if ($state['memory_bytes'] === null || $state['disk_bytes'] === null || $counters->isEmpty()) {
            $missing = collect([
                'memory' => $state['memory_bytes'] === null,
                'disk' => $state['disk_bytes'] === null,
                'network counters' => $counters->isEmpty(),
            ])->filter(fn ($absent) => $absent)->keys()->implode(', ');
            $this->storeFailure($machine->id, 'malformed', "The host answered without: {$missing}.");

            return;
        }

        MachineUsageSample::create([
            'machine_id' => $machine->id,
            'sampled_at' => now(),
            'memory_mb' => intdiv((int) $state['memory_bytes'], 1024 * 1024),
            'disk_mb' => intdiv((int) $state['disk_bytes'], 1024 * 1024),
            'tx_bytes_total' => $counters->sum(),
        ]);
    }

    private function storeFailure(int $machineId, string $kind, string $detail): void
    {
        MachineUsageSample::create([
            'machine_id' => $machineId,
            'sampled_at' => now(),
            'memory_mb' => null,
            'disk_mb' => null,
            'tx_bytes_total' => null,
            'failure_kind' => $kind,
            'failure_detail' => $detail,
        ]);
    }

    /**
     * Why the reading could not be taken, in the sample's own vocabulary: the host unreachable or
     * never connected is transport, a host that answered too late is timeout, a machine the host no
     * longer knows is missing, and a host refusal is a malformed answer as far as the reading goes.
     */
    private static function failureKind(string $exceptionKind): string
    {
        return match ($exceptionKind) {
            MachineException::TIMEOUT => 'timeout',
            MachineException::NOT_FOUND => 'missing',
            MachineException::REJECTED => 'malformed',
            default => 'transport',
        };
    }
}
