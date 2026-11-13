<?php

return [
    'urlscan' => [
        'api_key' => env('URLSCAN_API_KEY'),
        'base_url' => env('URLSCAN_BASE_URL', 'https://urlscan.io/api/v1'),
    ],
    'xposedornot' => [
        'base_url' => env('XPOSEDORNOT_BASE_URL', 'https://api.xposedornot.com/v1'),
    ],
];
