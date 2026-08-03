<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\GeneratePostController;
use App\Http\Controllers\Api\V1\PostController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('register', RegisterController::class)
        ->middleware('throttle:register')
        ->name('register');

    Route::post('login', LoginController::class)
        ->middleware('throttle:login')
        ->name('login');

    Route::get('posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('posts/{slug}', [PostController::class, 'show'])->name('posts.show');

    Route::get('posts/{post:slug}/comments', [CommentController::class, 'index'])->name('posts.comments.index');

    Route::prefix('email')->name('verification.')->group(function (): void {
        Route::get('verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:verification-link'])
            ->name('verify');

        Route::post('verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:verification-request')
            ->name('send');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', LogoutController::class)->name('logout');

        Route::get('user', AuthenticatedUserController::class)->name('user');

        Route::match(['put', 'patch'], 'user/profile', [ProfileController::class, 'update'])
            ->name('profile.update');

        Route::put('user/password', [ProfileController::class, 'updatePassword'])
            ->middleware('throttle:password-update')
            ->name('password.update');

        // Declared ahead of the resource routes so `generate` is matched as a
        // literal segment rather than swallowed as a post slug.
        Route::post('posts/generate', GeneratePostController::class)
            ->middleware('throttle:post-generation')
            ->name('posts.generate');

        Route::apiResource('posts', PostController::class)
            ->only(['store', 'update'])
            ->parameters(['posts' => 'post:slug']);

        Route::post('posts/{post:slug}/comments', [CommentController::class, 'store'])
            ->name('posts.comments.store');

        Route::apiResource('comments', CommentController::class)->only(['update', 'destroy']);
    });
});
