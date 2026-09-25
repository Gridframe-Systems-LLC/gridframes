<?php

namespace App\Livewire\Subscription;

use App\Actions\Stripe\CancelSubscriptionAtPeriodEnd;
use App\Actions\Stripe\RefundSubscription;
use App\Actions\Stripe\ResumeSubscription;
use App\Actions\Stripe\UpdateSubscriptionQuantity;
use App\Exceptions\SuiProofException;
use App\Models\SuiIdentity;
use App\Models\Team;
use App\Services\Sui\SuiIdentityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Stripe\StripeClient;

class Actions extends Component
{
    public $server_limits = 0;

    public int $quantity = UpdateSubscriptionQuantity::MIN_SERVER_LIMIT;

    public int $minServerLimit = UpdateSubscriptionQuantity::MIN_SERVER_LIMIT;

    public int $maxServerLimit = UpdateSubscriptionQuantity::MAX_SERVER_LIMIT;

    public ?array $pricePreview = null;

    public bool $isRefundEligible = false;

    public int $refundDaysRemaining = 0;

    public bool $refundCheckLoading = true;

    public bool $refundAlreadyUsed = false;

    public bool $refundLatestPayment = false;

    public string $billingInterval = 'monthly';

    public ?string $nextBillingDate = null;

    public ?string $suiChallenge = null;

    public ?string $suiChallengeExpiresAt = null;

    public string $suiAddress = '';

    public string $suiSignature = '';

    public string $suiMessage = '';

    public array $suiIdentities = [];

    public function mount(): void
    {
        $this->server_limits = Team::serverLimit();
        $this->quantity = (int) $this->server_limits;
        $this->billingInterval = currentTeam()->subscription?->billingInterval() ?? 'monthly';
        $this->loadSuiIdentities();
    }

    public function loadPricePreview(int $quantity): void
    {
        $this->quantity = $quantity;
        $result = (new UpdateSubscriptionQuantity)->fetchPricePreview(currentTeam(), $quantity);
        $this->pricePreview = $result['success'] ? $result['preview'] : null;
    }

    // Password validation is intentionally skipped for quantity updates.
    // Unlike refunds/cancellations, changing the server limit is a
    // non-destructive, reversible billing adjustment (prorated by Stripe).
    public function updateQuantity(string $password = ''): bool
    {
        if (! auth()->user()?->isAdminOfTeam(currentTeam()->id)) {
            abort(403);
        }

        if ($this->quantity < UpdateSubscriptionQuantity::MIN_SERVER_LIMIT) {
            $this->dispatch('error', 'Minimum server limit is '.UpdateSubscriptionQuantity::MIN_SERVER_LIMIT.'.');
            $this->quantity = UpdateSubscriptionQuantity::MIN_SERVER_LIMIT;

            return true;
        }

        if ($this->quantity === (int) $this->server_limits) {
            return true;
        }

        $result = (new UpdateSubscriptionQuantity)->execute(currentTeam(), $this->quantity);

        if ($result['success']) {
            $this->server_limits = $this->quantity;
            $this->pricePreview = null;
            $this->dispatch('success', 'Server limit updated to '.$this->quantity.'.');

            return true;
        }

        $this->dispatch('error', $result['error'] ?? 'Failed to update server limit.');
        $this->quantity = (int) $this->server_limits;

        return true;
    }

    public function loadRefundEligibility(): void
    {
        $this->checkRefundEligibility();
        $this->refundCheckLoading = false;
    }

    /** Stop a credit-paid plan from renewing; it stays in force until the period already paid ends. */
    public function stopCreditsRenewal(): void
    {
        $this->setCreditsRenewal(false);
    }

    public function resumeCreditsRenewal(): void
    {
        $this->setCreditsRenewal(true);
    }

    private function setCreditsRenewal(bool $renew): void
    {
        $team = currentTeam();
        if (! $team || ! auth()->user()?->isAdminOfTeam($team->id)) {
            abort(403);
        }
        $subscription = $team->subscription;
        if (! $subscription?->isPaidWithCredits()) {
            return;
        }
        $subscription->update(['stripe_cancel_at_period_end' => ! $renew]);
        $this->dispatch('success', $renew ? 'The plan will renew from your credits.' : 'The plan will not renew. It stays in force until the paid period ends.');
    }

    public function stripeCustomerPortal(): void
    {
        if (! auth()->user()?->isAdminOfTeam(currentTeam()->id)) {
            abort(403);
        }
        $session = getStripeCustomerPortalSession(currentTeam());
        redirect($session->url);
    }

    public function loadSuiIdentities(): void
    {
        $this->suiIdentities = currentTeam()->suiIdentities()
            ->whereNull('unbound_at')
            ->orderByDesc('verified_at')
            ->get()
            ->map(fn (SuiIdentity $identity) => [
                'id' => $identity->id,
                'sui_address' => $identity->sui_address,
                'sui_ns_name' => $identity->sui_ns_name,
                'verified_at' => $identity->verified_at->toFormattedDateString(),
            ])
            ->toArray();
    }

    public function startSuiChallenge(): void
    {
        if (! auth()->user()?->isAdminOfTeam(currentTeam()->id)) {
            abort(403);
        }
        $challenge = app(SuiIdentityService::class)->challenge(currentTeam());
        $this->suiChallenge = $challenge['message'];
        $this->suiChallengeExpiresAt = $challenge['expires_at']->toFormattedDateString();
        $this->suiMessage = $challenge['message'];
    }

    public function verifySuiIdentity(): void
    {
        if (! auth()->user()?->isAdminOfTeam(currentTeam()->id)) {
            abort(403);
        }
        try {
            $identity = app(SuiIdentityService::class)->verify(currentTeam(), $this->suiAddress, $this->suiMessage, $this->suiSignature);
        } catch (SuiProofException $e) {
            // Whatever went wrong, the challenge is spent: a new one is needed before another try.
            $this->suiChallenge = null;
            $this->dispatch('error', $e->getMessage());

            return;
        }
        $this->suiAddress = '';
        $this->suiSignature = '';
        $this->suiChallenge = null;
        $this->loadSuiIdentities();
        $label = $identity->sui_ns_name ? $identity->sui_ns_name.' ('.$identity->sui_address.')' : $identity->sui_address;
        $this->dispatch('success', $label.' is now bound to this team.');
    }

    public function removeSuiIdentity(int $identityId): void
    {
        if (! auth()->user()?->isAdminOfTeam(currentTeam()->id)) {
            abort(403);
        }
        $identity = currentTeam()->suiIdentities()
            ->where('id', $identityId)
            ->whereNull('unbound_at')
            ->first();
        if ($identity) {
            // The row stays; the address never binds to another team. This team may bind it again.
            $identity->update(['unbound_at' => now()]);
        }
        $this->loadSuiIdentities();
        $this->dispatch('success', 'The address is unbound from this team.');
    }

    public function refundSubscription(string $password): bool|string
    {
        if (! auth()->user()?->isAdminOfTeam(currentTeam()->id)) {
            abort(403);
        }

        if (! shouldSkipPasswordConfirmation() && ! Hash::check($password, auth()->user()->password)) {
            return 'Invalid password.';
        }

        $result = app(RefundSubscription::class)->execute(currentTeam());

        if ($result['success']) {
            $this->dispatch('success', 'Subscription refunded successfully.');
            $this->redirect(route('subscription.index'), navigate: true);

            return true;
        }

        $this->dispatch('error', 'Something went wrong with the refund. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

        return true;
    }

    public function cancelImmediately(string $password, array $selectedActions = []): bool|string
    {
        if (! auth()->user()?->isAdminOfTeam(currentTeam()->id)) {
            abort(403);
        }

        if (! shouldSkipPasswordConfirmation() && ! Hash::check($password, auth()->user()->password)) {
            return 'Invalid password.';
        }

        if (in_array('refundLatestPayment', $selectedActions, true)) {
            // Eligibility is re-validated server-side inside RefundSubscription::execute()
            $result = app(RefundSubscription::class)->execute(currentTeam());

            if ($result['success']) {
                $this->dispatch('success', 'Subscription refunded and cancelled successfully.');
                $this->redirect(route('subscription.index'), navigate: true);

                return true;
            }

            $this->dispatch('error', 'Something went wrong with the refund. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

            return true;
        }

        $team = currentTeam();
        $subscription = $team->subscription;

        if (! $subscription?->stripe_subscription_id) {
            $this->dispatch('error', 'Something went wrong with the cancellation. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

            return true;
        }

        try {
            $stripe = app(StripeClient::class);
            $stripe->subscriptions->cancel($subscription->stripe_subscription_id);

            $subscription->update([
                'stripe_cancel_at_period_end' => false,
                'stripe_invoice_paid' => false,
                'stripe_trial_already_ended' => false,
                'stripe_past_due' => false,
                'stripe_feedback' => 'Cancelled immediately by user',
                'stripe_comment' => 'Subscription cancelled immediately by user at '.now()->toDateTimeString(),
            ]);

            $team->subscriptionEnded();

            \Log::info("Subscription {$subscription->stripe_subscription_id} cancelled immediately for team {$team->name}");

            $this->dispatch('success', 'Subscription cancelled successfully.');
            $this->redirect(route('subscription.index'), navigate: true);

            return true;
        } catch (\Exception $e) {
            \Log::error("Immediate cancellation error for team {$team->id}: ".$e->getMessage());

            $this->dispatch('error', 'Something went wrong with the cancellation. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

            return true;
        }
    }

    public function cancelAtPeriodEnd(string $password): bool|string
    {
        if (! auth()->user()?->isAdminOfTeam(currentTeam()->id)) {
            abort(403);
        }

        if (! shouldSkipPasswordConfirmation() && ! Hash::check($password, auth()->user()->password)) {
            return 'Invalid password.';
        }

        $result = (new CancelSubscriptionAtPeriodEnd)->execute(currentTeam());

        if ($result['success']) {
            $this->dispatch('success', 'Subscription will be cancelled at the end of the billing period.');

            return true;
        }

        $this->dispatch('error', 'Something went wrong with the cancellation. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

        return true;
    }

    public function resumeSubscription(): bool
    {
        if (! auth()->user()?->isAdminOfTeam(currentTeam()->id)) {
            abort(403);
        }

        $result = (new ResumeSubscription)->execute(currentTeam());

        if ($result['success']) {
            $this->dispatch('success', 'Subscription resumed successfully.');

            return true;
        }

        $this->dispatch('error', 'Something went wrong resuming the subscription. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

        return true;
    }

    private function checkRefundEligibility(): void
    {
        if (! isCloud() || ! currentTeam()->subscription?->stripe_subscription_id) {
            return;
        }

        try {
            $this->refundAlreadyUsed = currentTeam()->subscription?->stripe_refunded_at !== null;
            $result = (new RefundSubscription)->checkEligibility(currentTeam());
            $this->isRefundEligible = $result['eligible'];
            $this->refundDaysRemaining = $result['days_remaining'];

            if ($result['current_period_end']) {
                $this->nextBillingDate = Carbon::createFromTimestamp($result['current_period_end'])->format('M j, Y');
            }
        } catch (\Exception $e) {
            \Log::warning('Refund eligibility check failed: '.$e->getMessage());
        }
    }
}
