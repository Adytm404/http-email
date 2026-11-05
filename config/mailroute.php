<?php

return [
    // Requests per minute per API key on the public email API.
    'api_rate_limit' => (int) env('MAILROUTE_API_RATE_LIMIT', 600),
];
