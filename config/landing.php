<?php

return [
    /*
    |---------------------------------------------------------------------------
    | The public landing page
    |---------------------------------------------------------------------------
    |
    | A selling-mode instance is a storefront: strangers arrive at the apex, and
    | something has to tell them what this is before it asks them to sign up. A
    | self-hosted instance is a private workbench with no public to speak to, so
    | the landing is off there unless the operator turns it on deliberately.
    |
    */

    'enabled' => filter_var(
        env('LANDING_ENABLED', ! filter_var(env('SELF_HOSTED', true), FILTER_VALIDATE_BOOL)),
        FILTER_VALIDATE_BOOL
    ),

    /*
    | Who is selling. The licensee puts its own name here; nothing in the page
    | hardcodes an operator, so a rebrand is an environment change, not a patch.
    */
    'brand' => env('LANDING_BRAND', 'Xlaunch Deploy'),
    'legal_name' => env('LANDING_LEGAL_NAME', ''),

    /*
    | The promise, in the operator's own words. Defaults describe the product as
    | it actually behaves; an operator with a sharper pitch overrides them.
    */
    'headline' => env('LANDING_HEADLINE', 'Your own cloud, on machines you can point at.'),
    'subhead' => env('LANDING_SUBHEAD', 'Deploy applications, databases and services from a git push. Open an account, take a machine, and ship in minutes — with an API and an MCP endpoint so your agents can do it without you.'),

    /*
    | Where the page sends people who want more than the pitch. An empty value
    | removes the link rather than printing a dead one.
    */
    'docs_url' => env('LANDING_DOCS_URL', ''),
    'status_url' => env('LANDING_STATUS_URL', ''),
    'terms_url' => env('LANDING_TERMS_URL', ''),
    'privacy_url' => env('LANDING_PRIVACY_URL', ''),
    'support_email' => env('LANDING_SUPPORT_EMAIL', ''),

    /*
    | Where the machines run. Customers renting compute ask this first, and the
    | honest answer is a selling point rather than a detail to bury.
    */
    'region' => env('LANDING_REGION', ''),

    /*
    | Search engines. A demo instance shown to one partner should not be indexed;
    | a live storefront must be. Off by default so nothing leaks by accident.
    */
    'indexable' => filter_var(env('LANDING_INDEXABLE', false), FILTER_VALIDATE_BOOL),
];
