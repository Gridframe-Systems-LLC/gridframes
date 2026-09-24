<?php

namespace App\Livewire\Subscription;

use App\Actions\Stripe\CreateCheckoutSession;
use App\Actions\Subscription\SubscribeWithCredits;
use App\Exceptions\CreditsPaymentException;
use App\Exceptions\CheckoutUnavailableException;
use Livewire\Component;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

class PricingPlans extends Component
{
    /** The tier whose credit payment form is open, or null. */
    public ?string $creditsPlan = null;

    /** A gateway API key, held only for the one request that uses it. */
    public string $gatewayKey = '';

    public function chooseCredits(string $plan): void
    {
        $this->creditsPlan = data_get(plansOnSale()->get($plan), 'by_credits') ? $plan : null;
        $this->gatewayKey = '';
    }

    public function subscribeCredits(): mixed
    {
        $team = currentTeam();
        $user = auth()->user();
        if (! $team || ! $user?->isAdminOfTeam($team->id)) {
            abort(403);
        }
        $this->validate(['gatewayKey' => 'required|string|min:16|max:512']);
        $key = $this->gatewayKey;
        $this->gatewayKey = '';

        try {
            app(SubscribeWithCredits::class)->execute($team, (string) $this->creditsPlan, $key);
        } catch (CreditsPaymentException $e) {
            $this->dispatch('error', $e->getMessage());

            return null;
        }

        return redirect()->route('dashboard');
    }

    public function subscribeStripe(string $type): mixed
    {
        $team = currentTeam();
        $user = auth()->user();

        if (! $team || ! $user?->isAdminOfTeam($team->id)) {
            abort(403);
        }

        if ($team->subscription?->stripe_invoice_paid) {
            $this->dispatch('error', 'Team already has an active subscription.');

            return null;
        }

        $priceId = match ($type) {
            'dynamic-monthly' => config('subscription.stripe_price_id_dynamic_monthly'),
            'dynamic-yearly' => config('subscription.stripe_price_id_dynamic_yearly'),
            default => data_get(plansOnSale()->get($type), 'by_card') ? config("subscription.plans.{$type}.price_id") : null,
        };

        if (! $priceId) {
            $this->dispatch('error', 'Price ID not found! Please contact the administrator.');

            return null;
        }
        try {
            $session = app(CreateCheckoutSession::class)->execute($team, $user, $priceId);
        } catch (ApiErrorException $exception) {
            report($exception);
            $this->dispatch('error', 'Unable to confirm checkout with Stripe. Please try again shortly.');

            return null;
        } catch (CheckoutUnavailableException $exception) {
            $message = $exception->getMessage();
            if ($exception->billingPortalUrl) {
                $message .= ' <a href="'.e($exception->billingPortalUrl).'" target="_blank" rel="noopener noreferrer" class="underline">Open billing portal</a>';
            }
            $this->dispatch('error', $message);

            return null;
        } catch (RuntimeException $exception) {
            report($exception);
            $this->dispatch('error', 'Unable to start checkout. Please try again shortly.');

            return null;
        }

        return redirect($session->url, 303);
    }

    public function render()
    {
        // Only tiers that are actually on sale reach the page.
        return view('livewire.subscription.pricing-plans', [
            'plans' => plansOnSale(),
            'trialDays' => (int) config('subscription.trial_days'),
        ]);
    }
}
