<?php

namespace App\Actions\Machines;

use App\Exceptions\MachineException;
use App\Jobs\Machines\ProvisionMachineJob;
use App\Models\Machine;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a GridFrames machine: check the plan's pool and the host's capacity, make the SSH key,
 * record the machine, and queue its provisioning. Everything here is checked before anything is
 * built, so a refusal costs the customer nothing and a build that starts can finish.
 */
class CreateMachine
{
    /**
     * @throws MachineException when machines are off, the team has no qualifying plan, the size is
     *                          unknown or does not fit, or the host is full.
     */
    public function handle(Team $team, string $name, string $sizeKey, bool $acceptOverage = false, ?string $customerPublicKey = null): Machine
    {
        if (! config('machines.enabled')) {
            throw new MachineException(MachineException::REJECTED, 'Machines are turned off on this instance.');
        }

        $plan = static::planFor($team);
        if ($plan === null) {
            throw new MachineException(MachineException::REJECTED, 'You need an active GridFrames plan that includes machines before you can create one.');
        }

        $name = $this->normaliseName($name, $team);
        $size = $this->size($sizeKey);

        $customerPublicKey = trim((string) $customerPublicKey) ?: null;

        // Creation is serialized — per team by this lock, and on Postgres across teams by the advisory
        // lock below — so the usage the checks read is everything committed before this create. Two
        // submits racing the same pool (or the same host) cannot both pass on the usage they both saw.
        $lock = Cache::lock("machine-create:team:{$team->id}", 30);

        try {
            [$privateKey, $machine] = $lock->block(10, function () use ($team, $name, $sizeKey, $size, $plan, $acceptOverage, $customerPublicKey) {
                $created = DB::transaction(function () use ($team, $name, $sizeKey, $size, $plan, $acceptOverage) {
                    if (DB::connection()->getDriverName() === 'pgsql') {
                        // Held to commit: every other create, from any team, waits here in turn.
                        DB::select('select pg_advisory_xact_lock(hashtext(?)) as locked', ['machines.create']);
                    }

                    $pool = static::poolFor($team, $plan);
                    $usage = static::poolUsage($team);
                    $this->checkQuota($plan, $pool, $usage, $size, $acceptOverage);
                    $this->checkHostCapacity(static::hostUsage(), $size);

                    // The key and the row are made together: a machine recorded without its key could never be
                    // logged into, and a key recorded without its machine would linger unowned by any build.
                    $keyData = generateSSHKey('ed25519');
                    $privateKey = PrivateKey::createAndStore([
                        'name' => "GridFrames machine {$name}",
                        'description' => 'Created by GridFrames',
                        'private_key' => $keyData['private'],
                        'team_id' => $team->id,
                    ]);

                    $machine = Machine::create([
                        'uuid' => Str::uuid()->toString(),
                        'team_id' => $team->id,
                        'name' => $name,
                        'size_key' => $sizeKey,
                        'vcpu' => $size['vcpu'],
                        'memory_mb' => $size['memory_mb'],
                        'disk_gb' => $size['disk_gb'],
                        'incus_project' => 'default',
                        'incus_name' => "gf-{$team->id}-{$name}",
                        'state' => 'creating',
                    ]);

                    return [$privateKey, $machine];
                });

                // Dispatched under the lock: a crashed run leaves the row, which the next create counts.
                ProvisionMachineJob::dispatch($created[1], $created[0]->id, $customerPublicKey);

                return $created;
            });
        } catch (LockTimeoutException) {
            throw new MachineException(MachineException::REJECTED, 'Another machine creation is still under way; try again in a moment.');
        }

        return $machine;
    }

    /**
     * The plan a team's qualifying subscription holds, or null when the team is neither subscribed
     * nor trialing or its plan grants no machine pool. The same activity test the finance page uses.
     */
    public static function planFor(Team $team): ?array
    {
        $subscription = $team->subscription;
        if ($subscription === null) {
            return null;
        }

        $active = $subscription->isPaidWithCredits()
            ? $subscription->creditsPaidThrough()
            : (bool) $subscription->stripe_invoice_paid;
        if (! $active && ! $subscription->isTrialing()) {
            return null;
        }

        $plan = config('subscription.plans.'.$subscription->planKey());
        if (! is_array($plan)) {
            return null;
        }
        if (static::poolFields($plan)['vcpu'] <= 0
            && static::poolFields($plan)['memory_mb'] <= 0
            && static::poolFields($plan)['disk_gb'] <= 0) {
            return null;
        }

        return $plan;
    }

    /**
     * Whether the new-machine screen should exist for the team at all.
     */
    public static function availableFor(Team $team): bool
    {
        return (bool) config('machines.enabled') && static::planFor($team) !== null;
    }

    /**
     * The plan's pool after the per-member multiplier, in the same integers the quota check uses.
     *
     * @return array{vcpu:int, memory_mb:int, disk_gb:int, machines:int}|null
     */
    public static function poolFor(Team $team, ?array $plan = null): ?array
    {
        $plan ??= static::planFor($team);
        if ($plan === null) {
            return null;
        }

        $fields = static::poolFields($plan);
        $members = $plan['per_member'] ? max(1, $team->members()->count()) : 1;

        return [
            'vcpu' => $fields['vcpu'] * $members,
            'memory_mb' => $fields['memory_mb'] * $members,
            'disk_gb' => $fields['disk_gb'] * $members,
            'machines' => $fields['machines'] * $members,
        ];
    }

    /**
     * What the team's live machines hold today. A failed machine holds nothing: its instance was
     * deleted when provisioning failed, so it is not counted against the pool or the host.
     *
     * @return array{vcpu:int, memory_mb:int, disk_gb:int, machines:int}
     */
    public static function poolUsage(Team $team): array
    {
        $machines = $team->machines()->where('state', '!=', 'failed')->get(['vcpu', 'memory_mb', 'disk_gb']);

        return [
            'vcpu' => (int) $machines->sum('vcpu'),
            'memory_mb' => (int) $machines->sum('memory_mb'),
            'disk_gb' => (int) $machines->sum('disk_gb'),
            'machines' => $machines->count(),
        ];
    }

    /**
     * What every team's live machines hold today — the whole host's commitment, not one team's, so
     * the capacity check below fences the machine every tenant shares. The same
     * failed-holds-nothing rule as poolUsage.
     *
     * @return array{vcpu:int, memory_mb:int, disk_gb:int}
     */
    public static function hostUsage(): array
    {
        $machines = Machine::query()->where('state', '!=', 'failed')->get(['vcpu', 'memory_mb', 'disk_gb']);

        return [
            'vcpu' => (int) $machines->sum('vcpu'),
            'memory_mb' => (int) $machines->sum('memory_mb'),
            'disk_gb' => (int) $machines->sum('disk_gb'),
        ];
    }

    /**
     * @param  array{vcpu:int, memory_mb:int, disk_gb:int, machines:int}  $pool
     * @param  array{vcpu:int, memory_mb:int, disk_gb:int, machines:int}  $usage
     * @param  array{vcpu:int, memory_mb:int, disk_gb:int}  $size
     */
    private function checkQuota(array $plan, array $pool, array $usage, array $size, bool $acceptOverage): void
    {
        if ($pool['machines'] > 0 && $pool['machines'] < $usage['machines'] + 1) {
            throw new MachineException(
                MachineException::REJECTED,
                "Your plan includes {$pool['machines']} machine(s) and you already have {$usage['machines']}. Delete one or upgrade your plan to create more.",
            );
        }

        if ($pool['vcpu'] < $usage['vcpu'] + $size['vcpu']) {
            $this->refuseOverPool('vCPU', $usage['vcpu'], $pool['vcpu'], 'CPUs', false, false);
        }

        $memoryOver = $pool['memory_mb'] < $usage['memory_mb'] + $size['memory_mb'];
        if ($memoryOver) {
            $this->refuseOverPool('memory', $usage['memory_mb'], $pool['memory_mb'], 'MB', true, $acceptOverage && (int) $plan['overage_memory_cents_per_gb_month'] > 0);
        }

        $diskOver = $pool['disk_gb'] < $usage['disk_gb'] + $size['disk_gb'];
        if ($diskOver) {
            $this->refuseOverPool('disk', $usage['disk_gb'], $pool['disk_gb'], 'GB', true, $acceptOverage && (int) $plan['overage_disk_cents_per_gb_month'] > 0);
        }
    }

    /**
     * A resource that fits is never questioned; one that does not is refused unless the plan can
     * bill the excess and the customer has accepted the billing.
     */
    private function refuseOverPool(string $resource, int $used, int $pool, string $unit, bool $billable, bool $allowed): void
    {
        if ($allowed) {
            return;
        }

        $tail = $billable
            ? 'Overage billing is off for your plan, or has not been accepted, so pick a size that fits.'
            : 'Overage is not offered on your plan, so pick a size that fits or upgrade.';

        throw new MachineException(
            MachineException::REJECTED,
            "This machine does not fit your plan's {$resource} pool: you have used {$used} of {$pool} {$unit}. {$tail}",
        );
    }

    /**
     * @param  array{vcpu:int, memory_mb:int, disk_gb:int, machines:int}  $usage
     * @param  array{vcpu:int, memory_mb:int, disk_gb:int}  $size
     */
    private function checkHostCapacity(array $usage, array $size): void
    {
        $host = config('machines.host');
        $overcommit = max(1.0, (float) ($host['memory_overcommit'] ?? 1.0));
        // Kept integer: the sellable memory is the host's memory times the overcommit, floored.
        $sellableMemoryMb = (int) floor((int) $host['memory_mb'] * $overcommit);

        if ((int) $host['vcpu'] < $usage['vcpu'] + $size['vcpu']
            || $sellableMemoryMb < $usage['memory_mb'] + $size['memory_mb']
            || (int) $host['disk_gb'] < $usage['disk_gb'] + $size['disk_gb']) {
            throw new MachineException(MachineException::REJECTED, 'GridFrames is at capacity; try a smaller size or contact support');
        }
    }

    /**
     * A machine's name is its hostname and part of its Incus and SSH addresses, so it is a single
     * DNS label, lowercase, and unique per team including machines already deleted.
     */
    private function normaliseName(string $name, Team $team): string
    {
        $name = strtolower(trim($name));

        if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name)) {
            throw new MachineException(MachineException::REJECTED, 'The name may only contain lowercase letters, numbers and hyphens, and must start and end with a letter or number (1-63 characters).');
        }

        $taken = Machine::withTrashed()
            ->where('team_id', $team->id)
            ->where('name', $name)
            ->exists();
        if ($taken) {
            throw new MachineException(MachineException::REJECTED, "You already have a machine named '{$name}'. Pick another name.");
        }

        return $name;
    }

    /**
     * @return array{vcpu:int, memory_mb:int, disk_gb:int, machines:int}
     */
    private static function poolFields(array $plan): array
    {
        return [
            'vcpu' => (int) ($plan['pool_vcpu'] ?? 0),
            'memory_mb' => (int) ($plan['pool_memory_mb'] ?? 0),
            'disk_gb' => (int) ($plan['pool_disk_gb'] ?? 0),
            'machines' => (int) ($plan['max_machines'] ?? 0),
        ];
    }

    /**
     * @return array{vcpu:int, memory_mb:int, disk_gb:int}|null
     */
    private function size(string $sizeKey): array
    {
        $size = config("machines.sizes.{$sizeKey}");
        if (! is_array($size)) {
            throw new MachineException(MachineException::REJECTED, "Machine size '{$sizeKey}' is not offered on this instance.");
        }

        return [
            'vcpu' => (int) $size['vcpu'],
            'memory_mb' => (int) $size['memory_mb'],
            'disk_gb' => (int) $size['disk_gb'],
        ];
    }
}
