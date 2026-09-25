<?php

namespace App\Jobs;

use App\Actions\Subscription\ChargeCreditsPeriod;
use App\Exceptions\CreditsPaymentException;
use App\Jobs\Machines\EnforceMachineLifecycleJob;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Charge every credit-paid plan whose paid period ends within the next twelve hours.
 *
 * Runs hourly and is safe to run twice: each period has one charge reference, which the gateway takes at
 * most once. A balance that cannot cover the renewal leaves the plan in force for its grace days, and the
 * next run tries again.
 */
class RenewCreditSubscriptionsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Most plans renewed in one run. More waiting is logged and picked up by the next run. */
    public const BATCH = 500;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(ChargeCreditsPeriod $chargePeriod): void
    {
        $due = Subscription::query()
            ->where('payment_provider', 'credits')
            ->where('stripe_cancel_at_period_end', false)
            ->whereNotNull('paid_until')
            ->whereNotNull('gateway_customer_id')
            ->where('paid_until', '<=', now()->addHours(12))
            ->orderBy('paid_until')
            ->limit(self::BATCH + 1)
            ->get();

        if ($due->count() > self::BATCH) {
            Log::warning('Credit renewals: more plans due than one run takes; the rest wait for the next run.', ['batch' => self::BATCH]);
            $due = $due->take(self::BATCH);
        }

        foreach ($due as $subscription) {
            $planKey = $subscription->plan_key;
            if (! $planKey || (int) config("subscription.plans.{$planKey}.price_cents", 0) <= 0) {
                // A tier that no longer has a price is never charged at a guessed one.
                Log::warning('Credit renewal skipped: the plan has no credit price on this instance.', ['team_id' => $subscription->team_id, 'plan' => $planKey]);

                continue;
            }
            try {
                $chargePeriod->execute($subscription, $planKey, (int) $subscription->gateway_customer_id, $subscription->paid_until->copy());
                $this->syncMachines($subscription);
            } catch (CreditsPaymentException $e) {
                Log::info('Credit renewal not taken.', ['team_id' => $subscription->team_id, 'kind' => $e->kind]);
            }
        }
    }

    /**
     * A paid renewal reopens the plan, so machines stopped over payment can start again; machines
     * whose grace has run out are caught by the hourly lifecycle job on its own schedule.
     */
    private function syncMachines(Subscription $subscription): void
    {
        $team = $subscription->team;
        if ($team && config('machines.enabled') && $team->machines()->exists()) {
            EnforceMachineLifecycleJob::dispatch($team);
        }
    }
}
