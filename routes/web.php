<?php

use App\Http\Controllers\EmailDownloadController;
use App\Livewire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard/start');

Route::middleware('guest')->group(function () {
    Route::get('/login', Livewire\Auth\Login::class)->name('login');
});

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/login');
})->middleware('auth')->name('logout');

Route::middleware('auth')->prefix('dashboard')->group(function () {
    // Landing page: the first section this user's role can open.
    Route::get('/start', function (Request $request) {
        foreach (['view_dashboard' => 'dashboard', 'view_emails' => 'emails', 'send_emails' => 'compose', 'manage_providers' => 'providers', 'manage_api_keys' => 'keys'] as $permission => $route) {
            if ($request->user()->can($permission)) {
                return redirect()->route($route);
            }
        }

        return redirect()->route('docs');
    })->name('home');

    Route::get('/', Livewire\Overview::class)->name('dashboard')->middleware('can:view_dashboard');

    Route::middleware('can:view_emails')->group(function () {
        Route::get('/emails', Livewire\Emails\Index::class)->name('emails');
        Route::get('/emails/{email}', Livewire\Emails\Show::class)->name('emails.show')->whereUuid('email');
    });
    Route::middleware('can:view_email_content')->group(function () {
        Route::get('/emails/{email}/eml', [EmailDownloadController::class, 'eml'])->name('emails.eml')->whereUuid('email');
        Route::get('/emails/{email}/attachments/{attachment}', [EmailDownloadController::class, 'attachment'])->name('emails.attachment')->whereUuid('email');
    });
    Route::get('/emails-export', [EmailDownloadController::class, 'export'])->name('emails.export')->middleware('can:export_emails');
    Route::get('/send', Livewire\Compose::class)->name('compose')->middleware('can:send_emails');

    Route::get('/providers', Livewire\Providers::class)->name('providers')->middleware('can:manage_providers');
    Route::get('/routing', Livewire\Routing::class)->name('routing')->middleware('can:manage_routing');
    Route::get('/keys', Livewire\ApiKeys::class)->name('keys')->middleware('can:manage_api_keys');
    Route::get('/docs', Livewire\Docs::class)->name('docs');

    Route::get('/users', Livewire\Users::class)->name('users')->middleware('can:manage_users');
    Route::get('/roles', Livewire\Roles::class)->name('roles')->middleware('can:manage_roles');
    Route::get('/settings', Livewire\Settings::class)->name('settings')->middleware('can:manage_settings');
});
