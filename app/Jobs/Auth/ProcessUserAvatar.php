<?php

namespace App\Jobs\Auth;

use App\Jobs\Blog\RefreshAuthorPostsCache;
use App\Models\User;
use App\Services\Auth\ProfileService;
use App\Services\Media\ImageResizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Derives a small avatar thumbnail from an uploaded profile picture.
 *
 * Avatars are shown next to every byline, so the list view wants a tiny file
 * rather than the multi-megapixel original the user picked from their phone.
 */
class ProcessUserAvatar implements ShouldQueue
{
    use Queueable;

    /**
     * The widest a generated avatar thumbnail may be, in pixels.
     */
    private const THUMBNAIL_WIDTH = 200;

    /**
     * Discard the job when the model it carries has been deleted.
     *
     * Queued jobs restore their models by id, which throws before `handle()`
     * ever runs — so a user or post deleted while a job was still queued would
     * otherwise land in `failed_jobs` for no useful reason.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    public function __construct(public User $user)
    {
        $this->onQueue('images');
    }

    /**
     * Execute the job.
     */
    public function handle(ImageResizer $resizer): void
    {
        if (! $resizer->isAvailable()) {
            Log::warning('Skipping avatar thumbnail because the GD extension is unavailable.', [
                'user_id' => $this->user->getKey(),
            ]);

            return;
        }

        $user = $this->user->fresh();

        if ($user?->avatar_path === null) {
            return;
        }

        $disk = Storage::disk(ProfileService::AVATAR_DISK);

        if (! $disk->exists($user->avatar_path)) {
            Log::warning('Avatar vanished before its thumbnail could be generated.', [
                'user_id' => $user->getKey(),
                'avatar_path' => $user->avatar_path,
            ]);

            return;
        }

        $thumbnail = $resizer->scaleToWidth((string) $disk->get($user->avatar_path), self::THUMBNAIL_WIDTH);

        if ($thumbnail === null) {
            Log::warning('Avatar could not be decoded into a thumbnail.', [
                'user_id' => $user->getKey(),
                'avatar_path' => $user->avatar_path,
            ]);

            return;
        }

        $thumbnailPath = ProfileService::AVATAR_THUMBNAIL_DIRECTORY.'/'.basename($user->avatar_path);

        $disk->put($thumbnailPath, $thumbnail);

        User::query()
            ->whereKey($user->getKey())
            ->toBase()
            ->update(['avatar_thumbnail_path' => $thumbnailPath]);

        // Bylines on cached posts carry the avatar, so they are now stale.
        RefreshAuthorPostsCache::dispatch($user);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Failed to generate the avatar thumbnail.', [
            'user_id' => $this->user->getKey(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
