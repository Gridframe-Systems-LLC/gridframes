<?php

namespace App\Livewire\Admin;

use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Stripe\StripeClient;
use Throwable;

/**
 * The operator's view of the money: who pays for what, what came in this month by card and by credits,
 * what is at risk, and whether the prices this instance quotes still match what Stripe charges.
 *
 * Everything counted here comes from the platform's own records, which Stripe's webhooks and the credit
 * renewals keep current. The two figures only Stripe knows, card revenue and its price list, are read
 * live and shown as not measured when Stripe does not answer: a failed read is never shown as a zero.
 */
class Finance extends Component
{
    /** Most Stripe invoices read for one month's card revenue; beyond it the figure says it is partial. */
    private const INVOICE_CEILING = 1000;

    public function mount(): void
    {
        if (! isCloud() && ! isDev()) {
            abort(403);
        }
        if (! Auth::check() || Auth::id() !== 0) {
            abort(403);
        }
    }

    public function render()
    {
        $plans = collect(config('subscription.plans', []));
        $monthStart = now()->startOfMonth();

        $byPlan = [];
        $paying = [];
        $trialsEnding = collect();
        $unknownPlan = 0;
        foreach (Subscription::query()->with('team:id,name')->get() as $subscription) {
            $active = $subscription->isPaidWithCredits() ? $subscription->creditsPaidThrough() : (bool) $subscription->stripe_invoice_paid;
            if (! $active) {
                continue;
            }
            $key = $subscription->planKey();
            if (! $key || ! $plans->has($key)) {
                $unknownPlan++;

                continue;
            }
            $row = $byPlan[$key] ?? ['card' => 0, 'credits' => 0, 'trial' => 0, 'stopping' => 0, 'past_due' => 0];
            if ($subscription->isTrialing()) {
                $row['trial']++;
                if ($subscription->trial_ends_at->lte(now()->addDays(7))) {
                    $trialsEnding->push($subscription);
                }
            } else {
                $row[$subscription->isPaidWithCredits() ? 'credits' : 'card']++;
                $paying[] = $key;
            }
            $row['stopping'] += $subscription->stripe_cancel_at_period_end ? 1 : 0;
            $row['past_due'] += $subscription->stripe_past_due ? 1 : 0;
            $byPlan[$key] = $row;
        }

        $prices = $this->stripePrices($plans);
        $monthlyCents = 0;
        $unpriced = 0;
        foreach ($paying as $key) {
            $cents = (int) data_get($plans->get($key), 'price_cents', 0);
            if ($cents <= 0 && ($prices[$key]['ok'] ?? false)) {
                $cents = (int) $prices[$key]['amount'];
            }
            $cents > 0 ? $monthlyCents += $cents : $unpriced++;
        }

        $creditsThisMonth = (int) SubscriptionCharge::where('status', 'paid')->where('created_at', '>=', $monthStart)->sum('amount_cents');
        $cardThisMonth = $this->stripeRevenueSince($monthStart->timestamp);
        $share = config('subscription.license_share_percent');

        return view('livewire.admin.finance', [
            'plans' => $plans,
            'byPlan' => $byPlan,
            'unknownPlan' => $unknownPlan,
            'monthlyCents' => $monthlyCents,
            'unpriced' => $unpriced,
            'creditsThisMonth' => $creditsThisMonth,
            'cardThisMonth' => $cardThisMonth,
            'share' => $share,
            'prices' => $prices,
            'trialsEnding' => $trialsEnding->sortBy('trial_ends_at'),
            'failedCharges' => SubscriptionCharge::with('team:id,name')->where('status', 'failed')->where('updated_at', '>=', now()->subDays(30))->latest('updated_at')->limit(20)->get(),
            'recentCharges' => SubscriptionCharge::with('team:id,name')->where('status', 'paid')->latest()->limit(20)->get(),
            'month' => $monthStart->format('F Y'),
        ]);
    }

    /**
     * What Stripe charges for each tier that has a Stripe price.
     *
     * @return array<string, array{ok: bool, amount?: int, failure?: string}>
     */
    private function stripePrices($plans): array
    {
        if (! isStripe()) {
            return [];
        }

        return Cache::remember('admin-finance:stripe-prices', 60, function () use ($plans) {
            $out = [];
            foreach ($plans as $key => $plan) {
                $priceId = data_get($plan, 'price_id');
                if (blank($priceId)) {
                    continue;
                }
                try {
                    $price = app(StripeClient::class)->prices->retrieve($priceId);
                    $out[$key] = ['ok' => true, 'amount' => (int) $price->unit_amount];
                } catch (Throwable $e) {
                    $out[$key] = ['ok' => false, 'failure' => class_basename($e)];
                }
            }

            return $out;
        });
    }

    /**
     * Card revenue paid since a moment, read from Stripe's paid invoices.
     *
     * @return array{ok: bool, cents?: int, count?: int, partial?: bool, failure?: string}
     */
    private function stripeRevenueSince(int $since): array
    {
        if (! isStripe()) {
            return ['ok' => false, 'failure' => 'Stripe is not configured'];
        }

        return Cache::remember("admin-finance:stripe-revenue:{$since}", 60, function () use ($since) {
            try {
                $cents = 0;
                $count = 0;
                $invoices = app(StripeClient::class)->invoices->all(['status' => 'paid', 'created' => ['gte' => $since], 'limit' => 100]);
                foreach ($invoices->autoPagingIterator() as $invoice) {
                    if ($count >= self::INVOICE_CEILING) {
                        return ['ok' => true, 'cents' => $cents, 'count' => $count, 'partial' => true];
                    }
                    $cents += (int) $invoice->amount_paid;
                    $count++;
                }

                return ['ok' => true, 'cents' => $cents, 'count' => $count, 'partial' => false];
            } catch (Throwable $e) {
                return ['ok' => false, 'failure' => class_basename($e)];
            }
        });
    }
}
