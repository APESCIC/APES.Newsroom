<?php

use App\Http\Controllers\Dev\DevLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Local-only development routes
|--------------------------------------------------------------------------
|
| Registered from web.php only when APP_ENV=local. Controllers also
| abort_unless local as a second gate.
|
*/

Route::post('/_dev/login/{role}', [DevLoginController::class, 'login'])
    ->where('role', 'public|staff|admin')
    ->name('dev.login');

Route::post('/_dev/logout', [DevLoginController::class, 'logout'])
    ->name('dev.logout');
