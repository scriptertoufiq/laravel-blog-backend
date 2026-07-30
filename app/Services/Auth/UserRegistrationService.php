<?php

namespace App\Services\Auth;

use App\Jobs\Auth\SendEmailVerificationLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserRegistrationService
{
    /**
     * Register a new user and queue their email verification link.
     *
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function register(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = User::create($attributes);

            SendEmailVerificationLink::dispatch($user)->afterCommit();

            return $user;
        });
    }
}
