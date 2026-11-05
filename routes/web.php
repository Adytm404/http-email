<?php

use App\Livewire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

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
    Route::get('/', Livewire\Overview::class)->name('dashboard');
    Route::get('/emails', Livewire\Emails\Index::class)->name('emails');
    Route::get('/emails/{email}', Livewire\Emails\Show::class)->name('emails.show')->whereUuid('email');
    Route::get('/providers', Livewire\Providers::class)->name('providers');
    Route::get('/routing', Livewire\Routing::class)->name('routing');
    Route::get('/keys', Livewire\ApiKeys::class)->name('keys');
    Route::get('/docs', Livewire\Docs::class)->name('docs');
    Route::get('/settings', Livewire\Settings::class)->name('settings');
});
