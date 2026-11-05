<?php

return [
    // Requests per minute per API key on the public email API.
    'api_rate_limit' => (int) env('MAILROUTE_API_RATE_LIMIT', 600),

    // Start the queue worker from the scheduler (cron) instead of a supervisor.
    'queue_via_scheduler' => (bool) env('MAILROUTE_QUEUE_VIA_SCHEDULER', false),
];
