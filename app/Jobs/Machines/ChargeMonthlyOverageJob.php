<?php

namespace App\Jobs\Machines;

use App\Exceptions\CreditsPaymentException;
use App\Models\Machine;
use App\Models\MachineOverageCharge;
use App\Models\Team;
use App\Services\GatewayCredits;
use App\Services\Machines\StripeMeters;
use App\Services\Machines\UsageCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Throwable;

/**
 * Charges what a team used past its plan's pool in the month that just ended, on the morning of the
 * 1st for the month before.
 *
 * Every month's overage has one charge reference, written as a pending row before anything is asked
 * of a provider and moved to paid or failed after. The reference makes a re-run harmless: a paid or
 * failed row is never touched again, and a pending one is retried, which is how a run whose provider
 * was unreachable waits here instead of waiting a month.
 *
 * A card-paid team's overage is reported as meter events on the Billing Meters created by
 * `machines:stripe-meters`; Stripe prices the meter and invoices it with the plan. A credit-paid
 * team's overage is charged from its gateway balance under the same reference, exactly like a plan
 * renewal. The row's cents are this instance's own prices for the same quantities.
 */
class ChargeMonthlyOverageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(UsageCalculator $calculator, GatewayCredits $gateway, StripeClient $stripe, StripeMeters $meters): void
    {
        $periodEnd = now()->startOfMonth();
        $this->chargePeriod($calculator, $gateway, $stripe, $meters, $periodEnd->copy()->subMonthNoOverflow()->format('Y-m'));

        // Older runs that could not ask their provider left pending rows; retry them here so a
        // transient outage does not wait for the next 1st. Paid and failed rows are never revisited.
        $stalePeriods = MachineOverageCharge::query()
            ->where('status', 'pending')
            ->where('period', '!=', $periodEnd->copy()->subMonthNoOverflow()->format('Y-m'))
            ->pluck('period')
            ->unique();
        foreach ($stalePeriods as $period) {
            $this->chargePeriod($calculator, $gateway, $stripe, $meters, $period);
        }
    }

    private function chargePeriod(UsageCalculator $calculator, GatewayCredits $gateway, StripeClient $stripe, StripeMeters $meters, string $period): void
    {
        $from = Carbon::parse($period.'-01')->startOfDay();
        $to = $from->copy()->addMonthNoOverflow();

        // With trashed: a team whose only machine the lifecycle job destroyed still owes the month
        // that machine ran, and the soft delete is what hides it from a plain query.
        $teamIds = Machine::withTrashed()->where('team_id', '>', 0)->distinct()->pluck('team_id');
        foreach (Team::whereIn('id', $teamIds)->orderBy('id')->get() as $team) {
            try {
                $this->chargeTeam($calculator, $gateway, $stripe, $meters, $team, $period, $from, $to);
            } catch (Throwable $e) {
                Log::error('Machine overage: charging a team did not finish; its row waits in pending.', [
                    'team_id' => $team->id,
                    'period' => $period,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function chargeTeam(UsageCalculator $calculator, GatewayCredits $gateway, StripeClient $stripe, StripeMeters $meters, Team $team, string $period, Carbon $from, Carbon $to): void
    {
        $reference = MachineOverageCharge::reference($team->id, $period);
        $charge = MachineOverageCharge::where('charge_ref', $reference)->first();
        if ($charge && $charge->status !== 'pending') {
            return;
        }

        $plan = $calculator->plan($team);
        if (! $plan['overage_enabled']) {
            return;
        }

        $usage = $calculator->team($team, $from, $to);
        $overage = $calculator->overageCents($usage, $plan);
        if ($overage['total_cents'] <= 0) {
            return;
        }

        // The row is written first, in pending, so the money and the record cannot drift apart: a
        // crash after the provider took the payment still names the charge on the next run.
        $charge ??= MachineOverageCharge::create([
            'team_id' => $team->id,
            'period' => $period,
            'memory_cents' => $overage['memory_cents'],
            'disk_cents' => $overage['disk_cents'],
            'transfer_cents' => $overage['transfer_cents'],
            'total_cents' => $overage['total_cents'],
            'provider' => $team->subscription?->isPaidWithCredits() ? 'credits' : 'stripe',
            'charge_ref' => $reference,
            'status' => 'pending',
        ]);

        if ($charge->provider === 'credits') {
            $this->chargeFromCredits($gateway, $team, $charge, $overage['total_cents'], $period);

            return;
        }
        $this->reportToStripe($stripe, $meters, $team, $charge, $overage, $to);
    }

    private function chargeFromCredits(GatewayCredits $gateway, Team $team, MachineOverageCharge $charge, int $totalCents, string $period): void
    {
        $customerId = (int) $team->subscription?->gateway_customer_id;
        if (! $customerId) {
            Log::error('Machine overage: the plan is paid from credits but no gateway account is on record; the charge waits in pending.', [
                'team_id' => $team->id,
                'charge_ref' => $charge->charge_ref,
            ]);

            return;
        }

        try {
            $result = $gateway->charge($customerId, $totalCents, $charge->charge_ref, "GridFrames machines overage, {$period}");
        } catch (CreditsPaymentException $e) {
            if ($e->kind === CreditsPaymentException::UNAVAILABLE) {
                Log::error('Machine overage: the gateway did not answer; the charge waits in pending.', [
                    'team_id' => $team->id,
                    'charge_ref' => $charge->charge_ref,
                    'kind' => $e->kind,
                ]);

                return;
            }
            // A refusal is a fact: the balance could not cover it, or the gateway said no. The row
            // is failed and a run never retries it on its own.
            $charge->update(['status' => 'failed']);
            Log::warning('Machine overage: the gateway refused the charge.', [
                'team_id' => $team->id,
                'charge_ref' => $charge->charge_ref,
                'kind' => $e->kind,
            ]);

            return;
        }

        // `applied` is false when the gateway had taken this reference before: still paid, once.
        $charge->update(['status' => 'paid', 'provider_reference' => (string) $result['chargeId']]);
    }

    /**
     * @param  array{memory_cents: int, disk_cents: int, transfer_cents: int, total_cents: int, memory_gb: int, disk_gb: int, transfer_gb: int}  $overage
     */
    private function reportToStripe(StripeClient $stripe, StripeMeters $meters, Team $team, MachineOverageCharge $charge, array $overage, Carbon $periodEnd): void
    {
        $customerId = $team->subscription?->stripe_customer_id;
        if (! $customerId) {
            Log::error('Machine overage: the plan is paid by card but no Stripe customer is on record; the charge waits in pending.', [
                'team_id' => $team->id,
                'charge_ref' => $charge->charge_ref,
            ]);

            return;
        }

        $quantities = [
            'memory' => $overage['memory_gb'],
            'disk' => $overage['disk_gb'],
            'transfer' => $overage['transfer_gb'],
        ];
        $references = [];
        try {
            foreach ($quantities as $resource => $units) {
                if ($units <= 0) {
                    continue;
                }
                // Backdated into the usage month, inside Stripe's 35-day event window for a run on
                // the 1st; the identifier is the charge reference, so a retry deduplicates.
                $event = $stripe->billing->meterEvents->create([
                    'event_name' => $meters->eventName($resource),
                    'identifier' => "{$charge->charge_ref}-{$resource}",
                    'timestamp' => $periodEnd->copy()->subMinute()->getTimestamp(),
                    'payload' => ['value' => (string) $units, 'stripe_customer_id' => $customerId],
                ]);
                $references[] = (string) $event->id;
            }
        } catch (Throwable $e) {
            Log::error('Machine overage: Stripe did not take the meter events; the charge waits in pending.', [
                'team_id' => $team->id,
                'charge_ref' => $charge->charge_ref,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $charge->update(['status' => 'paid', 'provider_reference' => implode(',', $references)]);
    }
}
