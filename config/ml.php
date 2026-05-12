<?php

return [
    'enabled' => env('ML_ENABLED', true),
    'base_url' => env('ML_BASE_URL', 'https://raamwhy-finary-model.hf.space'),
    'timeout' => (int) env('ML_TIMEOUT', 4),
    'verify_ssl' => env('ML_VERIFY_SSL', true),
];
