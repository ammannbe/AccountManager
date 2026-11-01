<?php

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('login', [OidcController::class, 'login'])->middleware('guest')->name('login');
Route::post('logout', [OidcController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('throttle:20,1')->group(function () {
    Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->name('oidc.callback');
});

// Self-registration of an account, optionally with a service (double opt-in by email).
Route::get('register', [RegistrationController::class, 'create'])->name('registrations.account');
Route::post('register', [RegistrationController::class, 'store'])->middleware('throttle:10,1')->name('registrations.account.store');
Route::get('register/sent', [RegistrationController::class, 'sent'])->name('registrations.account.sent');
Route::get('register/{service}', [RegistrationController::class, 'create'])->name('registrations.create');
Route::post('register/{service}', [RegistrationController::class, 'store'])->middleware('throttle:10,1')->name('registrations.store');
Route::get('register/{service}/sent', [RegistrationController::class, 'sent'])->name('registrations.sent');
Route::get('confirm/{token}', [RegistrationController::class, 'show'])->name('registrations.show');
Route::post('confirm/{token}', [RegistrationController::class, 'confirm'])->middleware('throttle:10,1')->name('registrations.confirm');

Route::middleware('auth')->group(function () {
    Route::get('/', [PortalController::class, 'index'])->name('portal');
    Route::post('services/{service}/access', [PortalController::class, 'claim'])->name('services.claim');
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('profile', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('profile.update');
});

// Confirmation of a new email address: signed link, works without being logged in.
Route::middleware(['signed', 'throttle:10,1'])->group(function () {
    Route::get('profile/email/{user}', [ProfileController::class, 'showEmail'])->name('profile.email');
    Route::post('profile/email/{user}', [ProfileController::class, 'confirmEmail'])->name('profile.email.confirm');
});
