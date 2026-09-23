<?php

use App\Http\Controllers\Api\Content\V1\ContentResourceController;
use App\Http\Middleware\AuthenticateContentApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('content/v1')
    ->middleware([AuthenticateContentApiKey::class, 'throttle:content-api'])
    ->group(function () {
        Route::get('/posts', [ContentResourceController::class, 'posts']);
        Route::get('/posts/{slug}', [ContentResourceController::class, 'post']);
        Route::get('/pages', [ContentResourceController::class, 'pages']);
        Route::get('/pages/{slug}', [ContentResourceController::class, 'page']);
        Route::get('/tags', [ContentResourceController::class, 'tags']);
        Route::get('/tags/{slug}', [ContentResourceController::class, 'tag']);
    });
