<?php

return [
    // Requests per minute per API key on the public email API.
    'api_rate_limit' => (int) env('MAILROUTE_API_RATE_LIMIT', 600),

    // Start the queue worker from the scheduler (cron) instead of a supervisor.
    'queue_via_scheduler' => (bool) env('MAILROUTE_QUEUE_VIA_SCHEDULER', false),

    // Failed dashboard sign-ins allowed per email+IP before a lockout (an IP gets 4x this across all emails).
    'login_max_attempts' => (int) env('MAILROUTE_LOGIN_MAX_ATTEMPTS', 5),
    'login_lockout_minutes' => (int) env('MAILROUTE_LOGIN_LOCKOUT_MINUTES', 30),

    // Required (as ?setup=...) to create the first admin from the login page in production.
    'setup_token' => env('MAILROUTE_SETUP_TOKEN'),
];
