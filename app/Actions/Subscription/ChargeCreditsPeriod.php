<?php

namespace App\Actions\Subscription;

use App\Exceptions\CreditsPaymentException;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Services\GatewayCredits;
use Illuminate\Support\Carbon;

/**
 * Take one month of a credit-paid plan, starting at a given moment, and record what happened.
 *
 * The charge reference is the team, the tier and the day the period starts, so the first payment, a
 * renewal and any retry of either all name the same charge and the gateway takes it at most once.
 */
class ChargeCreditsPeriod
{
    public function __construct(private GatewayCredits $gateway) {}

    public static function reference(int $teamId, string $planKey, Carbon $periodStart): string
    {
        return "team-{$teamId}-{$planKey}-{$periodStart->format('Ymd')}";
    }

    public function execute(Subscription $subscription, string $planKey, int $customerId, Carbon $periodStart): SubscriptionCharge
    {
        $plan = config("subscription.plans.{$planKey}");
        $cents = (int) data_get($plan, 'price_cents', 0);
        if ($cents <= 0) {
            throw new CreditsPaymentException(CreditsPaymentException::REFUSED, 'This plan cannot be paid with credits.');
        }
        $periodEnd = $periodStart->copy()->addMonthNoOverflow();
        $reference = self::reference($subscription->team_id, $planKey, $periodStart);
        $host = parse_url((string) (instanceSettings()->fqdn ?? config('app.url')), PHP_URL_HOST) ?: 'platform';
        $reason = sprintf('%s on %s, %s to %s', data_get($plan, 'name', $planKey), $host, $periodStart->toDateString(), $periodEnd->toDateString());

        try {
            $result = $this->gateway->charge($customerId, $cents, $reference, $reason);
        } catch (CreditsPaymentException $e) {
            // Only a refusal is a fact worth recording; "we could not ask" is retried, never written down
            // as a failed payment.
            if ($e->kind !== CreditsPaymentException::UNAVAILABLE) {
                SubscriptionCharge::updateOrCreate(['charge_ref' => $reference], [
                    'team_id' => $subscription->team_id,
                    'provider' => 'credits',
                    'plan_key' => $planKey,
                    'amount_cents' => $cents,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'status' => 'failed',
                    'failure' => $e->getMessage(),
                ]);
            }
            throw $e;
        }

        $charge = SubscriptionCharge::updateOrCreate(['charge_ref' => $reference], [
            'team_id' => $subscription->team_id,
            'provider' => 'credits',
            'plan_key' => $planKey,
            'amount_cents' => $cents,
            'gateway_charge_id' => $result['chargeId'],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'paid',
            'failure' => null,
        ]);
        $subscription->update(['paid_until' => $periodEnd]);

        return $charge;
    }
}
