<?php

namespace App\Jobs\Blog;

use App\Models\Post;
use App\Services\Blog\PostService;
use App\Services\Media\ImageResizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Derives a web-sized thumbnail from an uploaded cover image.
 *
 * Resizing a multi-megapixel upload is far too slow to do while the author
 * waits, so the post is saved immediately and the thumbnail catches up here.
 */
class ProcessPostImage implements ShouldQueue
{
    use Queueable;

    /**
     * The widest a generated thumbnail may be, in pixels.
     */
    private const THUMBNAIL_WIDTH = 600;

    /**
     * The directory that generated thumbnails are written to.
     */
    private const THUMBNAIL_DIRECTORY = 'posts/thumbnails';

    /**
     * Discard the job when the model it carries has been deleted.
     *
     * Queued jobs restore their models by id, which throws before `handle()`
     * ever runs — so a user or post deleted while a job was still queued would
     * otherwise land in `failed_jobs` for no useful reason.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30];

    /**
     * The number of seconds the job can run before timing out.
     *
     * Kept below the queue connection's `retry_after` so a slow resize is never
     * released back onto the queue while it is still running.
     */
    public int $timeout = 60;

    public function __construct(public Post $post)
    {
        $this->onQueue('images');
    }

    /**
     * Execute the job.
     *
     * The generated thumbnail changes what the read endpoints should return, so
     * the post's cache entry is rewritten once the new path is stored.
     */
    public function handle(PostService $posts, ImageResizer $resizer): void
    {
        if (! $resizer->isAvailable()) {
            Log::warning('Skipping post thumbnail generation because the GD extension is unavailable.', [
                'post_id' => $this->post->getKey(),
            ]);

            return;
        }

        $post = $this->post->fresh();

        if ($post?->image_path === null) {
            return;
        }

        $disk = Storage::disk(PostService::IMAGE_DISK);

        if (! $disk->exists($post->image_path)) {
            Log::warning('Post image vanished before its thumbnail could be generated.', [
                'post_id' => $post->getKey(),
                'image_path' => $post->image_path,
            ]);

            return;
        }

        $thumbnail = $resizer->scaleToWidth((string) $disk->get($post->image_path), self::THUMBNAIL_WIDTH);

        if ($thumbnail === null) {
            Log::warning('Post image could not be decoded into a thumbnail.', [
                'post_id' => $post->getKey(),
                'image_path' => $post->image_path,
            ]);

            return;
        }

        $thumbnailPath = self::THUMBNAIL_DIRECTORY.'/'.basename($post->image_path);

        $disk->put($thumbnailPath, $thumbnail);

        Post::query()
            ->whereKey($post->getKey())
            ->toBase()
            ->update(['image_thumbnail_path' => $thumbnailPath]);

        $posts->refresh($post);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Failed to generate the post thumbnail.', [
            'post_id' => $this->post->getKey(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
