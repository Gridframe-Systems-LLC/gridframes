<?php

use App\Models\Team;
use Stripe\BillingPortal\Session;
use Stripe\Customer;
use Stripe\Stripe;

function isSubscriptionActive()
{
    return once(function () {
        if (! isCloud()) {
            return false;
        }
        $team = currentTeam();
        if (! $team) {
            return false;
        }
        // Root team (id=0) doesn't require subscription
        if ($team->id === 0) {
            return true;
        }
        $subscription = $team?->subscription;

        if (is_null($subscription)) {
            return false;
        }
        if ($subscription->payment_provider === 'credits') {
            return $subscription->creditsPaidThrough();
        }
        if (isStripe()) {
            return $subscription->stripe_invoice_paid === true;
        }

        return false;
    });
}

/**
 * Whether plans can be paid from Xlaunch gateway credits on this instance.
 */
function creditsPaymentsEnabled(): bool
{
    return filled(config('subscription.credits.gateway_url')) && filled(config('subscription.credits.token'));
}

/**
 * The tiers a customer can actually buy, keyed by tier, each with how it can be paid.
 *
 * A tier with no way to pay, or that grants no machines, would be an advertisement for something that
 * does not exist; it is dropped here rather than shown and apologised for.
 *
 * @return \Illuminate\Support\Collection<string, array<string, mixed>>
 */
function plansOnSale(): \Illuminate\Support\Collection
{
    $credits = creditsPaymentsEnabled();

    return collect(config('subscription.plans', []))
        ->filter(fn ($plan) => is_array($plan) && (int) data_get($plan, 'servers', 0) > 0)
        ->map(function (array $plan) use ($credits) {
            $plan['by_card'] = isStripe() && filled($plan['price_id'] ?? null);
            $plan['by_credits'] = $credits && (int) ($plan['price_cents'] ?? 0) > 0;
            if (blank($plan['price_label'] ?? null) && (int) ($plan['price_cents'] ?? 0) > 0) {
                $cents = (int) $plan['price_cents'];
                $plan['price_label'] = '$'.($cents % 100 === 0 ? intdiv($cents, 100) : number_format($cents / 100, 2));
            }

            return $plan;
        })
        ->filter(fn (array $plan) => $plan['by_card'] || $plan['by_credits']);
}

/**
 * The tier a price id belongs to, or null when it is none of this instance's tiers.
 */
function planKeyForPrice(?string $priceId): ?string
{
    if (blank($priceId)) {
        return null;
    }
    $key = collect(config('subscription.plans', []))->search(fn ($plan) => data_get($plan, 'price_id') === $priceId);

    return $key === false ? null : $key;
}

function isSubscriptionOnGracePeriod()
{
    return once(function () {
        $team = currentTeam();
        if (! $team) {
            return false;
        }
        $subscription = $team?->subscription;
        if (! $subscription) {
            return false;
        }
        if (isStripe()) {
            return $subscription->stripe_cancel_at_period_end;
        }

        return false;
    });
}
function subscriptionProvider()
{
    return config('subscription.provider');
}
function isStripe()
{
    return config('subscription.provider') === 'stripe';
}
function getStripeCustomerPortalSession(Team $team)
{
    Stripe::setApiKey(config('subscription.stripe_api_key'));
    $return_url = route('subscription.show');
    $stripe_customer_id = data_get($team, 'subscription.stripe_customer_id');
    if (! $stripe_customer_id) {
        return null;
    }

    return Session::create([
        'customer' => $stripe_customer_id,
        'return_url' => $return_url,
    ]);
}
function allowedPathsForUnsubscribedAccounts()
{
    return [
        'subscription/new',
        'login',
        'logout',
        'force-password-reset',
        'two-factor-challenge',
        'livewire/update',
        'admin',
        // Account basics stay available without a paid plan.
        'profile',
        'profile/appearance',
    ];
}
function allowedPathsForBoardingAccounts()
{
    return [
        ...allowedPathsForUnsubscribedAccounts(),
        'onboarding',
        'livewire/update',
    ];
}
function allowedPathsForInvalidAccounts()
{
    return [
        'logout',
        'verify',
        'force-password-reset',
        'two-factor-challenge',
        'livewire/update',
    ];
}

function updateStripeCustomerEmail(Team $team, string $newEmail): void
{
    if (! isStripe()) {
        return;
    }

    $stripe_customer_id = data_get($team, 'subscription.stripe_customer_id');
    if (! $stripe_customer_id) {
        return;
    }

    Stripe::setApiKey(config('subscription.stripe_api_key'));

    Customer::update(
        $stripe_customer_id,
        ['email' => $newEmail]
    );
}
