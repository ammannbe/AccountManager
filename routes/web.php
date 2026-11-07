<?php

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\EasterEggController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\HouseholdInvitationController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('login', [OidcController::class, 'login'])->middleware('guest')->name('login');
Route::post('logout', [OidcController::class, 'logout'])->middleware('auth')->name('logout');

// Public page the "All rights reserved." links of the mails (also of the other apps) lead to.
Route::get('easter-egg', EasterEggController::class)->name('easter-egg');

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

    Route::get('households', [HouseholdController::class, 'index'])->name('households.index');
    Route::post('households', [HouseholdController::class, 'store'])->middleware('throttle:20,1')->name('households.store');
    Route::put('households/{household}', [HouseholdController::class, 'update'])->name('households.update');
    Route::delete('households/{household}', [HouseholdController::class, 'destroy'])->name('households.destroy');
    Route::post('households/{household}/invitations', [HouseholdController::class, 'invite'])->middleware('throttle:20,1')->name('households.invitations.store');
    Route::delete('households/{household}/invitations/{invitation}', [HouseholdController::class, 'revoke'])->name('households.invitations.destroy');
    Route::put('households/{household}/members/{member}', [HouseholdController::class, 'updateMember'])->name('households.members.update');
    Route::delete('households/{household}/members/{member}', [HouseholdController::class, 'destroyMember'])->name('households.members.destroy');
    Route::post('household-invitations/{invitation}/accept', [HouseholdInvitationController::class, 'acceptOpen'])->name('household-invitations.accept');
    Route::post('household-invitations/{invitation}/decline', [HouseholdInvitationController::class, 'declineOpen'])->name('household-invitations.decline');
    Route::post('invitations/{token}', [HouseholdInvitationController::class, 'acceptToken'])->middleware('throttle:10,1')->name('household-invitations.accept-token');
    Route::post('invitations/{token}/decline', [HouseholdInvitationController::class, 'declineToken'])->middleware('throttle:10,1')->name('household-invitations.decline-token');
});

// Link of a household invitation: shows it to guests too (log in or create an account, then back here).
Route::get('invitations/{token}', [HouseholdInvitationController::class, 'show'])->middleware('throttle:20,1')->name('household-invitations.show');

// Confirmation of a new email address: signed link, works without being logged in.
Route::middleware(['signed', 'throttle:10,1'])->group(function () {
    Route::get('profile/email/{user}', [ProfileController::class, 'showEmail'])->name('profile.email');
    Route::post('profile/email/{user}', [ProfileController::class, 'confirmEmail'])->name('profile.email.confirm');
});
