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
            'plans' => plansOnSale()->all(),
            'trialDays' => (int) config('subscription.trial_days'),
            'credits' => creditsPaymentsEnabled(),
            'registrationOpen' => (bool) (instanceSettings()?->is_registration_enabled ?? false),
        ]);
    }
}
