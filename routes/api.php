<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\PostController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('register', RegisterController::class)
        ->middleware('throttle:register')
        ->name('register');

    Route::post('login', LoginController::class)
        ->middleware('throttle:login')
        ->name('login');

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

        Route::middleware('verified')->group(function (): void {
            Route::get('user', AuthenticatedUserController::class)->name('user');

            Route::apiResource('posts', PostController::class)->only('store');
        });
    });
});
