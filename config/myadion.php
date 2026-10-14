<?php

return [

    /*
    | The server application declared in the customer area (My account ›
    | Applications). Credentials of a test organization give a test client:
    | nothing else changes.
    */
    'client_id' => env('MYADION_CLIENT_ID'),

    'client_secret' => env('MYADION_CLIENT_SECRET'),

    /*
    | Scopes asked for the token, separated by spaces. Empty: all the scopes
    | of the application.
    */
    'scopes' => env('MYADION_SCOPES'),

    /*
    | The project sent in `x-api-project`. Optional when the application is
    | limited to a single project.
    */
    'project' => env('MYADION_PROJECT'),

    /*
    | The signing secret of your webhook endpoint (whsec_…).
    */
    'webhook_secret' => env('MYADION_WEBHOOK_SECRET'),

    'base_url' => env('MYADION_BASE_URL', 'https://adion-api.eu/api'),

    'timeout' => (float) env('MYADION_TIMEOUT', 30),

    'max_retries' => (int) env('MYADION_MAX_RETRIES', 2),

    /*
    | Keep the access token in the application cache, shared by every
    | process. Set to null to fetch one per process.
    */
    'token_cache_store' => env('MYADION_TOKEN_CACHE_STORE', 'default'),

];
