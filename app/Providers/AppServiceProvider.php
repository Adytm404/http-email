<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('email-api', function (Request $request) {
            $key = $request->attributes->get('api_key');

            return Limit::perMinute((int) config('mailroute.api_rate_limit'))->by($key ? 'key:'.$key->id : 'ip:'.$request->ip());
        });
    }
}
