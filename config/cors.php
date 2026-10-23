<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST'],

    /*
     * Only the React Router admin app may call the API from a browser. Patterns cover
     * tunnel URLs that change on every dev run.
     */
    'allowed_origins' => array_values(array_filter(explode(',', (string) env('FRONTEND_ORIGINS', '')))),

    'allowed_origins_patterns' => array_values(array_filter(explode(',', (string) env('FRONTEND_ORIGIN_PATTERNS', '')))),

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
