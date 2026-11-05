<?php

namespace App\Providers;

use App\Auth\Permission;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
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
        // Every ability in the permission registry is granted by the user's role.
        Gate::before(function (User $user, string $ability) {
            return array_key_exists($ability, Permission::ALL) ? $user->hasPermission($ability) : null;
        });

        RateLimiter::for('email-api', function (Request $request) {
            $key = $request->attributes->get('api_key');

            return Limit::perMinute((int) config('mailroute.api_rate_limit'))->by($key ? 'key:'.$key->id : 'ip:'.$request->ip());
        });
    }
}
