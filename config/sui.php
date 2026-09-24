<?php

/*
 * Proof of a Sui address, bound to a team. The proof is a signature over a challenge the platform
 * writes; the private key never reaches the platform, so a customer — an agent with its own wallet —
 * can prove an address it owns without surrendering it. Nothing here touches the chain: the chain is
 * consulted only to display the address's SuiNS name, and only when it answers.
 */
return [
    // Where the platform asks for the SuiNS name of a bound address, for display. Mainnet GraphQL;
    // the mainnet fullnodes no longer speak JSON-RPC.
    'graphql_url' => env('SUI_GRAPHQL_URL', 'https://graphql.mainnet.sui.io/graphql'),

    // Minutes a signing challenge may be answered before a fresh one is needed.
    'challenge_ttl' => max(1, (int) env('SUI_CHALLENGE_TTL_MINUTES', 15)),

    // Bound addresses per team. An address binds to one team, ever: that is what soul-bound means here.
    'max_per_team' => max(1, (int) env('SUI_MAX_IDENTITIES_PER_TEAM', 5)),
];
