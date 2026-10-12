<?php

use App\Http\Controllers\Account\EmailChangeController;
use App\Http\Controllers\Account\ReauthenticationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CloudronOidcController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:register');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.challenge.store');

    Route::get('login/magic-link', [MagicLinkController::class, 'create'])->name('magic-link.request');
    Route::post('login/magic-link', [MagicLinkController::class, 'store'])
        ->middleware('throttle:magic-link')
        ->name('magic-link.store');
    Route::get('login/magic-link/{token}', [MagicLinkController::class, 'consume'])
        ->middleware('signed')
        ->name('magic-link.consume');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:forgot-password')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');

    Route::get('auth/cloudron/redirect', [CloudronOidcController::class, 'redirect'])->name('cloudron.redirect');
});

// Shared by sign-in (guests) and SSO re-authentication (signed-in staff);
// the controller sends signed-in users without a re-auth request home.
Route::get('auth/cloudron/callback', [CloudronOidcController::class, 'callback'])
    ->middleware('throttle:oidc-callback')
    ->name('cloudron.callback');

// Email change links work whether or not the browser is signed in: the
// confirm link goes to the new address, the cancel link to the old one.
Route::middleware(['signed', 'throttle:email-change-link'])->prefix('account/email-change/{emailChange}')->group(function () {
    Route::get('confirm', [EmailChangeController::class, 'showConfirm'])->name('account.email.confirm');
    Route::post('confirm', [EmailChangeController::class, 'confirm'])->name('account.email.confirm.store');
    Route::get('cancel', [EmailChangeController::class, 'showCancel'])->name('account.email.cancel');
    Route::post('cancel', [EmailChangeController::class, 'cancel'])->name('account.email.cancel.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('account/confirm-identity', [ReauthenticationController::class, 'show'])->name('account.confirm-identity');
    Route::post('account/confirm-identity/password', [ReauthenticationController::class, 'confirmPassword'])
        ->middleware('throttle:reauth')
        ->name('account.confirm-identity.password');
    Route::post('account/confirm-identity/code/send', [ReauthenticationController::class, 'sendCode'])
        ->middleware('throttle:reauth')
        ->name('account.confirm-identity.code.send');
    Route::post('account/confirm-identity/code', [ReauthenticationController::class, 'confirmCode'])
        ->middleware('throttle:reauth')
        ->name('account.confirm-identity.code');
    Route::get('account/confirm-identity/sso', [CloudronOidcController::class, 'reauthenticate'])
        ->name('account.confirm-identity.sso');

    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});
