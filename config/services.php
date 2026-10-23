<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Chat model that writes replies, over the OpenAI-compatible chat completions API:
    // local Ollama for the prototype, or OpenRouter by changing these values.
    'chat' => [
        'url' => env('CHAT_API_URL', 'http://127.0.0.1:11434/v1/chat/completions'),
        'key' => env('CHAT_API_KEY'),
        'model' => env('CHAT_MODEL', 'qwen3.5:2b'),
        // "none" stops thinking models spending the whole token budget on hidden reasoning.
        'reasoning_effort' => env('CHAT_REASONING_EFFORT', 'none'),
        // Below Cloudflare's ~100 s edge timeout, so a slow model falls back to the plain guide text.
        'timeout' => (int) env('CHAT_TIMEOUT', 60),
    ],

    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),
        'required_scopes' => ['read_orders'],
        // Every scope requested in the frontend's shopify.app.toml. A stored token missing one is
        // renewed (at most every 10 minutes) so newly approved scopes take effect.
        'scopes' => ['read_orders', 'read_products', 'read_inventory', 'read_discounts', 'read_legal_policies'],
        // "fake" uses sample orders; "shopify" reads the connected store through the Admin API.
    ],

    // Decision engine speaking the Jev /v1/systemone protocol:
    // local Laya for the prototype, OpenRouter's Jev once approved.
    'decisions' => [
        'url' => env('DECISION_API_URL', 'http://127.0.0.1:8001/v1/systemone'),
        'key' => env('DECISION_API_KEY'),
        /** Local Laya: always "english". Left empty, Laya's router sends some English questions to its multilingual model and loads it mid-chat. */
        'model' => env('DECISION_MODEL') ?: 'english',
        /** Laya answers in under 1.5 s; after this many seconds the chat falls back to guide search + LLM instead of waiting. */
        'timeout' => (int) env('DECISION_TIMEOUT', 5),
        /** Token window shared by all option descriptions; empty uses Laya's default (192). */
        'head_max_len' => env('DECISION_HEAD_MAX_LEN'),
    ],

    /** Turns text into meaning vectors for guide search (local Ollama, OpenAI-compatible). */
    'embeddings' => [
        'url' => env('EMBEDDING_API_URL', 'http://127.0.0.1:11434/v1/embeddings'),
        'model' => env('EMBEDDING_MODEL', 'nomic-embed-text'),
        'timeout' => (int) env('EMBEDDING_TIMEOUT', 20),
    ],

];
