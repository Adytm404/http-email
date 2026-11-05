<?php

namespace App\Http\Middleware;

use App\Http\ApiError;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return ApiError::make(401, 'missing_api_key', 'Missing API key in the authorization header. Include the following header in the request: Authorization: Bearer YOUR_API_KEY');
        }

        $key = ApiKey::findByToken($token);
        if (! $key) {
            return ApiError::make(403, 'invalid_api_key', 'API key is invalid');
        }

        if ($ability === 'read' && ! $key->canRead()) {
            return ApiError::make(401, 'restricted_api_key', 'This API key is restricted to only send emails');
        }

        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
