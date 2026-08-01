<?php

namespace App\Services\Auth;

use App\Jobs\Auth\ProcessUserAvatar;
use App\Jobs\Auth\SendEmailVerificationLink;
use App\Jobs\Blog\RefreshAuthorPostsCache;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProfileService
{
    /**
     * The disk that avatars are written to.
     */
    public const AVATAR_DISK = 'public';

    /**
     * The directory that uploaded avatars are written to.
     */
    public const AVATAR_DIRECTORY = 'avatars';

    /**
     * The directory that generated avatar thumbnails are written to.
     */
    public const AVATAR_THUMBNAIL_DIRECTORY = 'avatars/thumbnails';

    /**
     * Apply profile changes for the given user.
     *
     * @param  array<string, string>  $attributes  only the fields the client sent
     * @param  bool  $removeAvatar  clear the current avatar without uploading one
     */
    public function update(
        User $user,
        array $attributes,
        ?UploadedFile $avatar = null,
        bool $removeAvatar = false,
    ): User {
        $nameChanged = array_key_exists('name', $attributes)
            && $attributes['name'] !== $user->name;

        $emailChanged = array_key_exists('email', $attributes)
            && $attributes['email'] !== $user->email;

        $avatarChanged = $avatar !== null || ($removeAvatar && $user->avatar_path !== null);

        $replacedPaths = $avatarChanged
            ? [$user->avatar_path, $user->avatar_thumbnail_path]
            : [];

        $newAvatarPath = $avatar?->store(self::AVATAR_DIRECTORY, self::AVATAR_DISK) ?: null;

        DB::transaction(function () use ($user, $attributes, $emailChanged, $avatarChanged, $newAvatarPath): void {
            $user->fill($attributes);

            // A new address has not been proven yet, so the old verification
            // cannot carry over to it.
            if ($emailChanged) {
                $user->email_verified_at = null;
            }

            if ($avatarChanged) {
                $user->avatar_path = $newAvatarPath;
                // The old thumbnail belongs to the old picture either way.
                $user->avatar_thumbnail_path = null;
            }

            $user->save();
        });

        $this->deleteStoredImages(...$replacedPaths);

        if ($emailChanged) {
            SendEmailVerificationLink::dispatch($user)->afterCommit();
        }

        if ($newAvatarPath !== null) {
            ProcessUserAvatar::dispatch($user)->afterCommit();
        }

        // Cached post payloads embed the author's name and avatar. A new avatar
        // is handled by ProcessUserAvatar once its thumbnail exists; a rename or
        // a removal has nothing to wait for, so refresh here.
        if ($nameChanged || ($avatarChanged && $newAvatarPath === null)) {
            RefreshAuthorPostsCache::dispatch($user)->afterCommit();
        }

        return $user;
    }

    /**
     * Remove avatar files that a replacement or removal has made redundant.
     */
    private function deleteStoredImages(?string ...$paths): void
    {
        $stale = array_values(array_filter($paths));

        if ($stale !== []) {
            Storage::disk(self::AVATAR_DISK)->delete($stale);
        }
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
