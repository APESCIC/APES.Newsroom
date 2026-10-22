<?php

use App\Http\Controllers\Api\Admin\V1\AdminPostController;
use App\Http\Controllers\Api\Content\V1\ContentResourceController;
use App\Http\Middleware\AuthenticateAdminApiToken;
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

Route::prefix('admin/v1')
    ->middleware([AuthenticateAdminApiToken::class, 'throttle:admin-api'])
    ->group(function () {
        Route::get('/posts', [AdminPostController::class, 'index'])->middleware('token.ability:posts:read');
        Route::post('/posts', [AdminPostController::class, 'store'])->middleware('token.ability:posts:write');
        Route::get('/posts/{post}', [AdminPostController::class, 'show'])->middleware('token.ability:posts:read');
        Route::patch('/posts/{post}', [AdminPostController::class, 'update'])->middleware('token.ability:posts:write');
        Route::post('/posts/{post}/publish', [AdminPostController::class, 'publish'])->middleware('token.ability:posts:publish');
        Route::post('/posts/{post}/unpublish', [AdminPostController::class, 'unpublish'])->middleware('token.ability:posts:publish');
    });
