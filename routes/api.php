<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('register', RegisterController::class)
        ->middleware('throttle:register')
        ->name('register');

    Route::prefix('email')->name('verification.')->group(function (): void {
        Route::get('verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:verification-link'])
            ->name('verify');

        Route::post('verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:verification-request')
            ->name('send');
    });
});
