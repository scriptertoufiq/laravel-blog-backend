<?php

namespace App\Jobs\Blog;

use App\Models\Post;
use App\Models\User;
use App\Services\Blog\PostService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clears the cached payloads of every post an author wrote.
 *
 * Post payloads embed the author's name, so renaming a user leaves a stale
 * byline on each of their cached posts. An author can have thousands of posts,
 * which is far too much work to do while they wait for a profile form to save.
 */
class RefreshAuthorPostsCache implements ShouldQueue
{
    use Queueable;

    /**
     * How many posts to clear per database round trip.
     */
    private const CHUNK = 500;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public int $timeout = 60;

    public function __construct(public User $author)
    {
        $this->onQueue('cache');
    }

    /**
     * Execute the job.
     */
    public function handle(PostService $posts): void
    {
        $cleared = 0;

        Post::query()
            ->where('user_id', $this->author->getKey())
            ->select(['id', 'slug'])
            ->chunkById(self::CHUNK, function ($chunk) use ($posts, &$cleared): void {
                foreach ($chunk as $post) {
                    $posts->forgetShow($post->slug);
                    $cleared++;
                }
            });

        // One version bump covers every cached listing page at once.
        $posts->forgetAllListings();

        Log::info('Cleared cached posts after an author profile change.', [
            'user_id' => $this->author->getKey(),
            'posts_cleared' => $cleared,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Failed to clear cached posts after an author profile change.', [
            'user_id' => $this->author->getKey(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
