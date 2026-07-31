<?php

namespace App\Jobs\Blog;

use App\Models\Post;
use App\Services\Blog\PostService;
use GdImage;
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
     * The number of times the job may be attempted.
     */
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
     * the post's cache entries are dropped once the new path is written.
     */
    public function handle(PostService $posts): void
    {
        if (! function_exists('imagecreatefromstring')) {
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

        $thumbnail = $this->thumbnailFrom((string) $disk->get($post->image_path));

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

        $posts->forget($post);
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

    /**
     * Scale the given image down, returning the encoded thumbnail bytes.
     */
    private function thumbnailFrom(string $contents): ?string
    {
        $dimensions = @getimagesizefromstring($contents);

        if ($dimensions === false) {
            return null;
        }

        [$width, $height] = $dimensions;

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return null;
        }

        $thumbnail = null;

        try {
            $scale = min(1, self::THUMBNAIL_WIDTH / max($width, 1));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);

            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            imagecopyresampled(
                $thumbnail, $source,
                0, 0, 0, 0,
                $targetWidth, $targetHeight,
                $width, $height,
            );

            return $this->encode($thumbnail, $dimensions[2]);
        } finally {
            imagedestroy($source);

            if ($thumbnail instanceof GdImage) {
                imagedestroy($thumbnail);
            }
        }
    }

    /**
     * Encode the thumbnail back into the format the original used.
     */
    private function encode(GdImage $thumbnail, int $imageType): ?string
    {
        ob_start();

        $encoded = match ($imageType) {
            IMAGETYPE_PNG => imagepng($thumbnail, null, 6),
            IMAGETYPE_WEBP => imagewebp($thumbnail, null, 82),
            default => imagejpeg($thumbnail, null, 82),
        };

        $contents = ob_get_clean();

        return $encoded && is_string($contents) && $contents !== '' ? $contents : null;
    }
}
