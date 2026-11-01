<?php

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('login', [OidcController::class, 'login'])->middleware('guest')->name('login');
Route::post('logout', [OidcController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('throttle:20,1')->group(function () {
    Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->name('oidc.callback');
});

// Self-registration per service (double opt-in by email).
Route::get('register/{service}', [RegistrationController::class, 'create'])->name('registrations.create');
Route::post('register/{service}', [RegistrationController::class, 'store'])->middleware('throttle:10,1')->name('registrations.store');
Route::get('register/{service}/sent', [RegistrationController::class, 'sent'])->name('registrations.sent');
Route::get('confirm/{token}', [RegistrationController::class, 'show'])->name('registrations.show');
Route::post('confirm/{token}', [RegistrationController::class, 'confirm'])->middleware('throttle:10,1')->name('registrations.confirm');

Route::middleware('auth')->group(function () {
    Route::get('/', [PortalController::class, 'index'])->name('portal');
    Route::post('services/{service}/access', [PortalController::class, 'claim'])->name('services.claim');
});
