<?php

namespace App\Services\Auth;

use App\Jobs\Auth\SendEmailVerificationLink;
use App\Jobs\Blog\RefreshAuthorPostsCache;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProfileService
{
    /**
     * Apply profile changes for the given user.
     *
     * @param  array<string, string>  $attributes  only the fields the client sent
     */
    public function update(User $user, array $attributes): User
    {
        $nameChanged = array_key_exists('name', $attributes)
            && $attributes['name'] !== $user->name;

        $emailChanged = array_key_exists('email', $attributes)
            && $attributes['email'] !== $user->email;

        DB::transaction(function () use ($user, $attributes, $emailChanged): void {
            $user->fill($attributes);

            // A new address has not been proven yet, so the old verification
            // cannot carry over to it.
            if ($emailChanged) {
                $user->email_verified_at = null;
            }

            $user->save();
        });

        if ($emailChanged) {
            SendEmailVerificationLink::dispatch($user)->afterCommit();
        }

        // Cached post payloads embed the author's name; renaming makes them stale.
        if ($nameChanged) {
            RefreshAuthorPostsCache::dispatch($user)->afterCommit();
        }

        return $user;
    }

    /**
     * Replace the user's password and sign their other devices out.
     *
     * The token behind the current request survives so the caller is not logged
     * out of the session they just used to change it.
     */
    public function updatePassword(User $user, string $newPassword, ?string $keepTokenId = null): User
    {
        DB::transaction(function () use ($user, $newPassword, $keepTokenId): void {
            $user->forceFill(['password' => $newPassword])->save();

            $user->tokens()
                ->when($keepTokenId !== null, fn ($query) => $query->whereKeyNot($keepTokenId))
                ->delete();
        });

        return $user;
    }
}
