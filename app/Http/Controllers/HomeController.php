<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * The apex of a selling-mode instance.
 *
 * Three people arrive at the same address and none of them want the same thing: a customer who is
 * already signed in wants their applications, a stranger wants to know what this is, and an operator
 * running privately wants no storefront at all. This decides between them once, at the door.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        // Someone with a session is not a prospect. Send them where their work is.
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        // A private instance has nothing to say to the public: behave exactly as it did before the
        // landing existed and hand the visitor straight to the sign-in form.
        if (! config('landing.enabled')) {
            return redirect()->route('login');
        }

        return response()->view('landing', [
            'plans' => $this->plansOnSale(),
            'registrationOpen' => (bool) (instanceSettings()?->is_registration_enabled ?? false),
        ]);
    }

    /**
     * The tiers a visitor can actually buy.
     *
     * A tier with no Stripe price cannot be checked out, so quoting it would be an advertisement for
     * something that does not exist. Those are dropped here rather than rendered and apologised for.
     *
     * @return array<string, array<string, mixed>>
     */
    private function plansOnSale(): array
    {
        $plans = config('subscription.plans', []);

        if (! is_array($plans)) {
            return [];
        }

        return array_filter(
            $plans,
            fn ($plan) => is_array($plan) && filled($plan['price_id'] ?? null)
        );
    }
}
