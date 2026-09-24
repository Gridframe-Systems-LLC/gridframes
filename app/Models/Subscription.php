<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'team_id',
        'stripe_invoice_paid',
        'stripe_subscription_id',
        'stripe_customer_id',
        'stripe_cancel_at_period_end',
        'stripe_plan_id',
        'stripe_feedback',
        'stripe_comment',
        'stripe_trial_already_ended',
        'stripe_past_due',
        'stripe_refunded_at',
        'payment_provider',
        'plan_key',
        'gateway_customer_id',
        'paid_until',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'stripe_refunded_at' => 'datetime',
            // Checked with === true across the codebase; without a cast SQLite hands back 1 and the check fails.
            'stripe_invoice_paid' => 'boolean',
            'stripe_cancel_at_period_end' => 'boolean',
            'stripe_past_due' => 'boolean',
            'paid_until' => 'datetime',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function charges()
    {
        return $this->hasMany(SubscriptionCharge::class, 'team_id', 'team_id');
    }

    public function isPaidWithCredits(): bool
    {
        return $this->payment_provider === 'credits';
    }

    /**
     * Whether a credit-paid plan still grants access: paid through a moment that has not passed, allowing
     * the grace days a failed renewal is given to be topped up.
     */
    public function creditsPaidThrough(): bool
    {
        if (! $this->isPaidWithCredits() || ! $this->paid_until) {
            return false;
        }

        return $this->paid_until->copy()->addDays((int) config('subscription.credits.grace_days'))->isFuture();
    }

    public function isTrialing(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * The tier this subscription holds: the recorded one for credits, the one its Stripe price belongs to
     * for a card.
     */
    public function planKey(): ?string
    {
        return $this->plan_key ?? planKeyForPrice($this->stripe_plan_id);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function billingInterval(): string
    {
        if ($this->stripe_plan_id) {
            $configKey = collect(config('subscription'))
                ->search($this->stripe_plan_id);

            if ($configKey && str($configKey)->contains('yearly')) {
                return 'yearly';
            }
        }

        return 'monthly';
    }

    public function type()
    {
        if (isStripe()) {
            if (! $this->stripe_plan_id) {
                return 'zero';
            }
            $subscription = Subscription::where('id', $this->id)->first();
            if (! $subscription) {
                return null;
            }
            $subscriptionPlanId = data_get($subscription, 'stripe_plan_id');
            if (! $subscriptionPlanId) {
                return null;
            }
            $subscriptionInvoicePaid = data_get($subscription, 'stripe_invoice_paid');
            if (! $subscriptionInvoicePaid) {
                return null;
            }
            $subscriptionConfigs = collect(config('subscription'));
            $stripePlanId = null;
            $subscriptionConfigs->map(function ($value, $key) use ($subscriptionPlanId, &$stripePlanId) {
                if ($value === $subscriptionPlanId) {
                    $stripePlanId = $key;
                }
            })->first();
            if ($stripePlanId) {
                return str($stripePlanId)->after('stripe_price_id_')->before('_')->lower();
            }
        }

        return 'zero';
    }
}
