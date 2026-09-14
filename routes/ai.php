<?php

use App\Mcp\Servers\XlaunchServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', XlaunchServer::class)
    ->middleware(['mcp.enabled', 'auth:sanctum', 'api.token.team', 'mcp.team.enabled']);
