<?php

use App\Http\ApiError;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['api.key' => AuthenticateApiKey::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Resend-compatible error bodies for the public API.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ApiError::isApiRequest($request)) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiError::make(422, 'validation_error', collect($e->errors())->flatten()->first() ?? 'Invalid request'),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiError::make(404, 'not_found', 'Email not found'),
                $e instanceof ThrottleRequestsException => ApiError::make(429, 'rate_limit_exceeded', 'Too many requests. Please slow down.', $e->getHeaders()),
                $e instanceof HttpExceptionInterface => ApiError::make($e->getStatusCode(), 'application_error', $e->getMessage() ?: 'Request failed'),
                default => ApiError::make(500, 'application_error', config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.'),
            };
        });
    })->create();
