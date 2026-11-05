<?php

use App\Http\Controllers\Api\EmailController;
use Illuminate\Support\Facades\Route;

// Paths mirror https://api.resend.com so the Resend SDKs work by changing the base URL.
// Everything is also available under /v1.
$routes = function () {
    Route::middleware(['api.key', 'throttle:email-api'])->group(function () {
        Route::post('emails', [EmailController::class, 'store']);
        Route::post('emails/batch', [EmailController::class, 'batch']);
    });

    Route::middleware(['api.key:read', 'throttle:email-api'])->group(function () {
        Route::get('emails', [EmailController::class, 'index']);
        Route::get('emails/{id}', [EmailController::class, 'show']);
        Route::patch('emails/{id}', [EmailController::class, 'update']);
        Route::post('emails/{id}/cancel', [EmailController::class, 'cancel']);
    });
};

Route::group([], $routes);
Route::prefix('v1')->group($routes);
