<?php

/*
 * One tier, read from the environment. A tier is shown and sold only when it grants machines and can be
 * paid: by card when it has a Stripe price, by gateway credits when it has a monthly price in cents and
 * the instance is connected to a gateway. The price label is what a visitor reads; the cents are what a
 * credit payment takes. Keep them equal to the Stripe price: the operator's finance page compares them
 * with Stripe and flags any difference.
 */
$tier = fn (string $prefix, string $name, string $tagline, string $lookupKey): array => [
    'name' => $name,
    'price_id' => env("STRIPE_PRICE_ID_{$prefix}", null),
    'price_label' => env("PLAN_{$prefix}_PRICE", ''),
    'price_cents' => max(0, (int) env("PLAN_{$prefix}_CENTS", 0)),
    'servers' => (int) env("PLAN_{$prefix}_SERVERS", 0),
    // The machine pool a plan grants and what using more costs, all in integers, zero meaning none
    // and zero overage meaning overage is off for the plan. A pool marked per member multiplies by
    // the team's member count.
    'pool_vcpu' => (int) env("PLAN_{$prefix}_POOL_VCPU", 0),
    'pool_memory_mb' => (int) env("PLAN_{$prefix}_POOL_MEMORY_MB", 0),
    'pool_disk_gb' => (int) env("PLAN_{$prefix}_POOL_DISK_GB", 0),
    'pool_transfer_gb' => (int) env("PLAN_{$prefix}_POOL_TRANSFER_GB", 0),
    'max_machines' => (int) env("PLAN_{$prefix}_MAX_MACHINES", 0),
    'overage_memory_cents_per_gb_month' => (int) env("PLAN_{$prefix}_OVERAGE_MEMORY_CENTS_PER_GB_MONTH", 0),
    'overage_disk_cents_per_gb_month' => (int) env("PLAN_{$prefix}_OVERAGE_DISK_CENTS_PER_GB_MONTH", 0),
    'overage_transfer_cents_per_gb' => (int) env("PLAN_{$prefix}_OVERAGE_TRANSFER_CENTS_PER_GB", 0),
    'per_member' => filter_var(env("PLAN_{$prefix}_PER_MEMBER", false), FILTER_VALIDATE_BOOL),
    'tagline' => $tagline,
    'machine' => env("PLAN_{$prefix}_MACHINE", ''),
    'lookup_key' => $lookupKey,
];

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

    // Free days before the first payment, once per team, the same for every customer. Zero is no trial.
    'trial_days' => max(0, (int) env('PLAN_TRIAL_DAYS', 0)),

    // Paying for a plan out of Xlaunch gateway credits, which an agent can top up in SUI. Off until both
    // the gateway's address and the platform token it issued are set.
    'credits' => [
        'gateway_url' => rtrim((string) env('GATEWAY_URL', ''), '/'),
        'token' => env('GATEWAY_PLATFORM_TOKEN'),
        // Days a credit-paid plan keeps working after a renewal the balance could not cover.
        'grace_days' => max(0, (int) env('PLAN_CREDITS_GRACE_DAYS', 3)),
    ],

    // The licensor's share of revenue, shown on the operator's finance page. Unset hides the line.
    'license_share_percent' => is_numeric(env('LICENSE_REVENUE_SHARE_PERCENT')) ? (float) env('LICENSE_REVENUE_SHARE_PERCENT') : null,

    // The tiers this instance sells, cheapest first: the landing page quotes the first one on sale as
    // its "from" price. What a tier grants is the licensee's commercial decision, not this code's.
    'plans' => [
        'agent_1gb' => $tier('AGENT_1GB', 'Agent 1 GB', 'One agent, one small machine of its own.', 'xlaunch_deploy_agent_1gb'),
        'agent_2gb' => $tier('AGENT_2GB', 'Agent 2 GB', 'An agent with a database or a heavier client.', 'xlaunch_deploy_agent_2gb'),
        'personal' => $tier('PERSONAL', 'Personal', 'One person, a few machines, everything the platform does.', 'xlaunch_deploy_personal'),
        'team' => $tier('TEAM', 'Team', 'A team on one account, with room to grow.', 'xlaunch_deploy_team'),
    ],
];
