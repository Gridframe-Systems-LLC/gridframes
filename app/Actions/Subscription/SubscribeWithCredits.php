<?php

namespace App\Actions\Subscription;

use App\Exceptions\CreditsPaymentException;
use App\Jobs\ServerLimitCheckJob;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\Team;
use App\Services\GatewayCredits;
use Illuminate\Support\Facades\Cache;

/**
 * Put a team on a plan paid from Xlaunch gateway credits.
 *
 * The customer proves the gateway account is theirs with one of its API keys. The key is used for that
 * one question and dropped; only the account id is kept. A team that never held a plan starts with the
 * same free trial a card customer gets, and nothing is charged until it ends.
 */
class SubscribeWithCredits
{
    public function __construct(
        private GatewayCredits $gateway,
        private ChargeCreditsPeriod $chargePeriod,
    ) {}

    public function execute(Team $team, string $planKey, string $apiKey): Subscription
    {
        $plan = plansOnSale()->get($planKey);
        if (! $plan || ! $plan['by_credits']) {
            throw new CreditsPaymentException(CreditsPaymentException::REFUSED, 'This plan cannot be paid with credits.');
        }

        $lock = Cache::lock("credits-subscribe:team:{$team->id}", 30);
        if (! $lock->get()) {
            throw new CreditsPaymentException(CreditsPaymentException::REFUSED, 'A payment for this team is already in progress.');
        }

        try {
            $existing = Subscription::where('team_id', $team->id)->first();
            if ($existing && ($existing->stripe_invoice_paid || $existing->creditsPaidThrough())) {
                throw new CreditsPaymentException(CreditsPaymentException::REFUSED, 'This team already has a plan in force.');
            }

            $account = $this->gateway->resolveKey($apiKey);
            $trialDays = (int) config('subscription.trial_days');
            $trial = $trialDays > 0 && ! $this->teamHadAPlan($team, $existing);

            $subscription = Subscription::updateOrCreate(['team_id' => $team->id], [
                'payment_provider' => 'credits',
                'plan_key' => $planKey,
                'gateway_customer_id' => $account['customerId'],
                'stripe_invoice_paid' => false,
                'stripe_cancel_at_period_end' => false,
                'stripe_past_due' => false,
                'trial_ends_at' => $trial ? now()->addDays($trialDays) : null,
                'paid_until' => $trial ? now()->addDays($trialDays) : null,
            ]);

            if (! $trial) {
                try {
                    $this->chargePeriod->execute($subscription, $planKey, $account['customerId'], now());
                } catch (CreditsPaymentException $e) {
                    // No plan was bought: leave no half-made subscription that looks like one.
                    $subscription->update(['payment_provider' => null, 'plan_key' => null, 'gateway_customer_id' => null, 'paid_until' => null]);
                    throw $e;
                }
            }

            $team->update(['custom_server_limit' => (int) $plan['servers']]);
            ServerLimitCheckJob::dispatch($team);

            return $subscription->fresh();
        } finally {
            $lock->release();
        }
    }

    private function teamHadAPlan(Team $team, ?Subscription $existing): bool
    {
        // A leftover stripe_customer_id counts too: subscriptionEnded() nulls the subscription id but
        // never the customer id, so a former card plan never looks like a team that never had one.
        return ($existing && (filled($existing->stripe_subscription_id) || filled($existing->stripe_customer_id) || $existing->trial_ends_at !== null || $existing->paid_until !== null))
            || SubscriptionCharge::where('team_id', $team->id)->exists();
    }
}
