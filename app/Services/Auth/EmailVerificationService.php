<?php

namespace App\Services\Auth;

use App\Jobs\Auth\SendEmailVerificationLink;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EmailVerificationService
{
    /**
     * Mark the user behind a signed verification link as verified.
     *
     * @return array{user: User, newly_verified: bool}
     *
     * @throws ModelNotFoundException<User>
     * @throws AuthorizationException
     */
    public function verify(int|string $userId, string $hash): array
    {
        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new AuthorizationException(__('The verification link is invalid.'));
        }

        if ($user->hasVerifiedEmail()) {
            return ['user' => $user, 'newly_verified' => false];
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return ['user' => $user, 'newly_verified' => true];
    }

    /**
     * Queue another verification link for the given email address.
     *
     * Returns silently when the address is unknown or already verified so the
     * endpoint cannot be used to enumerate registered accounts.
     */
    public function resendVerificationLink(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->hasVerifiedEmail()) {
            return;
        }

        SendEmailVerificationLink::dispatch($user);
    }
}
