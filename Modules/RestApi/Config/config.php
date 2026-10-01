<?php

return [
    /*
     * API Configuration
     */
    'api' => [
        'version' => 'v1',
        'prefix' => 'api',
        'rate_limit_default' => 1000, // Per hour
        'rate_limit_window' => 3600, // seconds (1 hour)
    ],

    /*
     * Webhook Configuration
     */
    'webhooks' => [
        'enabled' => true,
        'timeout' => 10, // seconds
        'retries' => 3,
        'backoff_factor' => 2,
    ],

    /*
     * Logging Configuration
     */
    'logging' => [
        'enabled' => true,
        'log_failed_requests' => true,
    ],

    /*
     * CORS Configuration (if serving from different domain)
     */
    'cors' => [
        'enabled' => false,
        'allowed_origins' => ['*'],
    ],
];
