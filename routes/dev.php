<?php

use App\Http\Controllers\Dev\DevLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Local-only development routes
|--------------------------------------------------------------------------
|
| Registered from web.php only when APP_ENV=local. Controllers also
| abort_unless DevLogin::enabled() (local + DEV_LOGIN_ENABLED) as a
| second gate.
|
*/

Route::get('/_dev', [DevLoginController::class, 'index'])
    ->name('dev.index');

Route::get('/_dev/login/{role}', [DevLoginController::class, 'loginForm'])
    ->where('role', 'public|staff|admin')
    ->name('dev.login.form');

Route::post('/_dev/login', [DevLoginController::class, 'login'])
    ->name('dev.login');

Route::post('/_dev/login/{role}', [DevLoginController::class, 'loginRole'])
    ->where('role', 'public|staff|admin')
    ->name('dev.login.role');

Route::post('/_dev/logout', [DevLoginController::class, 'logout'])
    ->name('dev.logout');
