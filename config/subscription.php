<?php

return [
    'provider' => env('SUBSCRIPTION_PROVIDER', null), // stripe

    // Stripe
    'stripe_api_key' => env('STRIPE_API_KEY', null),
    'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET', null),
    'stripe_excluded_plans' => env('STRIPE_EXCLUDED_PLANS', null),
    // Collect tax ids and let Stripe Tax compute tax at checkout. Requires the Stripe account's tax
    // settings to be complete; off by default so a fresh instance can sell at all.
    'stripe_automatic_tax' => filter_var(env('STRIPE_AUTOMATIC_TAX', false), FILTER_VALIDATE_BOOL),
    'stripe_price_id_dynamic_monthly' => env('STRIPE_PRICE_ID_DYNAMIC_MONTHLY', null),
    'stripe_price_id_dynamic_yearly' => env('STRIPE_PRICE_ID_DYNAMIC_YEARLY', null),

    // The tiers this instance sells. A tier with no price id is not on sale and is never shown;
    // the machine allowance and its specification are what the tier grants the team, read from the
    // environment because they are the licensee's commercial decision, not this code's. The public
    // landing page reads these same values, so what a visitor is quoted and what Stripe charges
    // cannot drift apart.
    'plans' => [
        'personal' => [
            'name' => 'Personal',
            'price_id' => env('STRIPE_PRICE_ID_PERSONAL', null),
            'price_label' => env('PLAN_PERSONAL_PRICE', ''),
            'servers' => (int) env('PLAN_PERSONAL_SERVERS', 0),
            'tagline' => 'One person, a few machines, everything the platform does.',
            'machine' => env('PLAN_PERSONAL_MACHINE', ''),
            'lookup_key' => 'xlaunch_deploy_personal',
        ],
        'team' => [
            'name' => 'Team',
            'price_id' => env('STRIPE_PRICE_ID_TEAM', null),
            'price_label' => env('PLAN_TEAM_PRICE', ''),
            'servers' => (int) env('PLAN_TEAM_SERVERS', 0),
            'tagline' => 'A team on one account, with room to grow.',
            'machine' => env('PLAN_TEAM_MACHINE', ''),
            'lookup_key' => 'xlaunch_deploy_team',
        ],
    ],
];
