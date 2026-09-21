<?php

return [
    'enabled' => (bool) env('API_DOCS_ENABLED', env('APP_ENV') !== 'production'),
    'title' => env('API_DOCS_TITLE', 'Core R API'),
    'version' => env('API_DOCS_VERSION', '1.0.0'),
];
