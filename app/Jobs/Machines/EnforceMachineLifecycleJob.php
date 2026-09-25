<?php

namespace App\Jobs\Machines;

use App\Exceptions\MachineException;
use App\Models\Machine;
use App\Models\Team;
use App\Notifications\TransactionalEmails\MachineLifecycleEmail;
use App\Services\Machines\IncusClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a team's machines in step with its plan, every hour.
 *
 * The plan's state is read from the same Subscription fields the rest of the platform acts on. For
 * credits that is `paid_until` and the renewal flag, exactly as RenewCreditSubscriptionsJob charges
 * from them: a period that passed without a payment gets the grace days, and a customer who stopped
 * renewing has ended their plan when the paid period is out. For a card the Stripe fields the
 * webhooks keep current tell the story: `past_due` is Stripe's own dunning and counts as grace,
 * while a subscription that is gone or unpaid has ended. A trial is a plan in force.
 *
 * What follows for the machines:
 * | Event            | Machines                                                        |
 * | Payment failed   | grace days, then stop with stopped_reason=payment and an email  |
 * | Payment recovers | machines stopped for payment start again                        |
 * | Plan ends        | stop at once, deletion 14 days later with emails on days 1, 7, 13, and a final snapshot kept 30 days before the delete |
 *
 * Nothing irreversible happens without a dry look first: the delete logs the machine, its instance
 * and its server before it takes the snapshot that outlives them.
 */
class EnforceMachineLifecycleJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Days after a plan ended that the team is warned their machines are going away. */
    public const DELETION_WARNING_DAYS = [1, 7, 13];

    /** Seconds the host may take for a stop, a snapshot or a delete before the run gives up and the next hour retries. */
    public const OPERATION_TIMEOUT = 120;

    public function __construct(public ?Team $team = null)
    {
        $this->onQueue('high');
    }

    public function handle(IncusClient $incus): void
    {
        $teams = $this->team
            ? collect([$this->team])
            : Team::query()->whereHas('machines')->orderBy('id')->get();
        foreach ($teams as $team) {
            try {
                $this->enforceTeam($team, $incus);
            } catch (Throwable $e) {
                Log::warning('Machine lifecycle: a team could not be enforced; the next hour tries again.', [
                    'team_id' => $team->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function enforceTeam(Team $team, IncusClient $incus): void
    {
        $planState = $this->planState($team);
        $machines = $team->machines()
            ->whereIn('state', ['running', 'stopped'])
            ->orderBy('id')
            ->get();

        foreach ($machines as $machine) {
            // A machine already on its way out after its plan ended: warn, then delete when due,
            // whatever the plan says today. Re-subscribing brings the pool back, not these machines.
            if ($machine->state === 'stopped' && $machine->stopped_reason === 'plan-ended') {
                $this->warnOfDeletion($machine);
                $this->destroyWhenDue($incus, $machine);

                continue;
            }
            if ($machine->state === 'stopped' && $machine->stopped_reason === 'payment') {
                if ($planState === 'active') {
                    $this->restart($incus, $machine);
                }

                continue;
            }
            if ($machine->state !== 'running') {
                continue;
            }
            if ($planState === 'ended') {
                $this->stopForPlanEnd($incus, $machine);

                continue;
            }
            if ($planState === 'payment') {
                $this->stopForPayment($incus, $machine);
            }
        }
    }

    /**
     * Where the team's plan stands, from the fields the payment flows already maintain.
     *
     * @return string active, payment (failed and out of grace) or ended
     */
    private function planState(Team $team): string
    {
        $subscription = $team->subscription;
        if (! $subscription) {
            return 'ended';
        }
        if ($subscription->isPaidWithCredits()) {
            if ($subscription->isTrialing()) {
                return 'active';
            }
            if ($subscription->stripe_cancel_at_period_end) {
                // The customer chose to end it: the plan ends when the paid period does, with no
                // grace beyond what they already paid for.
                return $subscription->paid_until?->isPast() ? 'ended' : 'active';
            }
            if (! $subscription->paid_until) {
                return 'ended';
            }

            // A renewal the balance could not cover: the plan holds through its grace days and the
            // charge is retried hourly, which is what keeps the machines running through it too.
            return $subscription->creditsPaidThrough() ? 'active' : 'payment';
        }

        // A card plan: past_due is Stripe retrying the card, which is the grace. A subscription
        // that is gone or unpaid has been through subscriptionEnded() and is over.
        if ($subscription->isTrialing()) {
            return 'active';
        }
        if (! $subscription->stripe_invoice_paid || ! $subscription->stripe_subscription_id) {
            return 'ended';
        }

        return 'active';
    }

    private function stopForPlanEnd(IncusClient $incus, Machine $machine): void
    {
        $stoppedAt = now();
        if (! $this->changeState($incus, $machine, 'stop', 'its plan has ended')) {
            return;
        }
        $deleteAfter = (int) config('machines.lifecycle.delete_after_stop_days');
        $machine->update([
            'state' => 'stopped',
            'stopped_reason' => 'plan-ended',
            'stopped_at' => $stoppedAt,
            'delete_after' => $stoppedAt->copy()->addDays($deleteAfter),
        ]);
        Log::info('Machine lifecycle: machine stopped because its plan ended.', [
            'machine' => $machine->name,
            'team_id' => $machine->team_id,
            'delete_after_days' => $deleteAfter,
        ]);
    }

    private function stopForPayment(IncusClient $incus, Machine $machine): void
    {
        if (! $this->changeState($incus, $machine, 'stop', 'a payment failed and its grace days are out')) {
            return;
        }
        $machine->update([
            'state' => 'stopped',
            'stopped_reason' => 'payment',
            'stopped_at' => now(),
            'delete_after' => null,
        ]);
        $this->emailAdmins($machine, 'GridFrames: your machines were stopped over an unpaid plan', [
            "A payment for your plan failed and its grace period is over, so machine '{$machine->name}' was stopped.",
            'The machine and its disk are kept. Once the plan is paid again it starts on its own within the hour.',
        ]);
    }

    private function restart(IncusClient $incus, Machine $machine): void
    {
        if (! $this->changeState($incus, $machine, 'start', 'its plan is paid again')) {
            return;
        }
        $machine->update([
            'state' => 'running',
            'stopped_reason' => null,
            'stopped_at' => null,
        ]);
        Log::info('Machine lifecycle: machine started again because its plan is paid.', [
            'machine' => $machine->name,
            'team_id' => $machine->team_id,
        ]);
    }

    private function warnOfDeletion(Machine $machine): void
    {
        if (! $machine->stopped_at) {
            return;
        }
        $daysStopped = (int) floor($machine->stopped_at->diffInDays(now()));
        if (! in_array($daysStopped, self::DELETION_WARNING_DAYS, true)) {
            return;
        }
        $keepDays = (int) config('machines.lifecycle.final_snapshot_keep_days');
        $sent = Cache::add("machines:lifecycle:deletion-warning:{$machine->uuid}:day-{$daysStopped}", true, now()->addDays($keepDays));
        if (! $sent) {
            return;
        }
        $this->emailAdmins($machine, 'GridFrames: machines from your ended plan are scheduled for deletion', [
            "Your plan has ended and machine '{$machine->name}' was stopped. It will be deleted in ".
            strval(max(0, (int) config('machines.lifecycle.delete_after_stop_days') - $daysStopped)).' days.',
            'A final snapshot is taken when it is deleted and kept '.strval($keepDays).' days, in case you want anything back.',
        ]);
    }

    private function destroyWhenDue(IncusClient $incus, Machine $machine): void
    {
        if (! $machine->delete_after || $machine->delete_after->isFuture()) {
            return;
        }

        // Ground rule 6: nothing irreversible without the record of what is about to go.
        $snapshotName = 'final-'.now()->format('YmdHis');
        $keepDays = (int) config('machines.lifecycle.final_snapshot_keep_days');
        Log::info('Machine lifecycle: deleting a machine whose plan ended. This is what will be removed:', [
            'machine' => $machine->name,
            'uuid' => $machine->uuid,
            'team_id' => $machine->team_id,
            'incus_project' => $machine->incus_project,
            'incus_instance' => $machine->incus_name,
            'server_id' => $machine->server_id,
            'size_key' => $machine->size_key,
            'snapshot_taken' => $snapshotName,
            'snapshot_kept_days' => $keepDays,
        ]);

        try {
            $snapshot = $incus->snapshot(
                $machine->incus_name,
                $snapshotName,
                now()->addDays($keepDays),
            );
            $incus->waitForOperation($snapshot['operation'], 600);
            $delete = $incus->delete($machine->incus_name);
            $incus->waitForOperation($delete['operation'], 600);
        } catch (MachineException $e) {
            Log::warning('Machine lifecycle: the host refused the delete; the next hour tries again.', [
                'machine' => $machine->name,
                'kind' => $e->kind,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $machine->update(['state' => 'deleted']);
        $machine->delete();
        Log::info('Machine lifecycle: machine deleted, its final snapshot kept.', [
            'machine' => $machine->name,
            'team_id' => $machine->team_id,
            'snapshot' => $snapshotName,
        ]);
    }

    /**
     * Ask the host for a state change and wait for it to land; only then does the row say so. A
     * host that refuses leaves the row as it was for the next hour to try again.
     */
    private function changeState(IncusClient $incus, Machine $machine, string $action, string $why): bool
    {
        try {
            $operation = $action === 'start'
                ? $incus->start($machine->incus_name)
                : $incus->stop($machine->incus_name);
            $incus->waitForOperation($operation['operation'], self::OPERATION_TIMEOUT);
        } catch (MachineException $e) {
            Log::warning('Machine lifecycle: the host refused a state change; the next hour tries again.', [
                'machine' => $machine->name,
                'action' => $action,
                'why' => $why,
                'kind' => $e->kind,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function emailAdmins(Machine $machine, string $subject, array $lines): void
    {
        $team = $machine->team;
        if (! $team) {
            return;
        }
        foreach ($team->members as $member) {
            if ($member->isAdmin()) {
                $member->notify(new MachineLifecycleEmail($subject, $lines));
            }
        }
    }
}
