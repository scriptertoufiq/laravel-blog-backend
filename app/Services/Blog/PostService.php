<?php

namespace App\Services\Blog;

use App\Http\Resources\Api\V1\PostResource;
use App\Jobs\Blog\ProcessPostImage;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostService
{
    /**
     * The disk that post images are written to.
     */
    public const IMAGE_DISK = 'public';

    /**
     * The directory that post images are written to.
     */
    public const IMAGE_DIRECTORY = 'posts/images';

    /**
     * How long a cached listing or post is served before it is rebuilt.
     */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Holds the generation number that every listing key is stamped with.
     *
     * The configured cache store is `database`, which cannot do `Cache::tags()`,
     * so listings are invalidated by bumping this number instead. Every old key
     * is orphaned in one write and ages out on its own TTL.
     */
    private const LIST_VERSION_KEY = 'posts:list:version';

    /**
     * Read a page of posts, serving it from cache whenever one is warm.
     *
     * Fully rendered payloads are cached rather than Eloquent objects, because
     * `cache.serializable_classes` is `false` — this application refuses to
     * unserialize PHP objects out of the cache, and arrays sidestep that
     * entirely while staying smaller on the wire.
     *
     * @return array<string, mixed>
     */
    public function paginate(int $page, int $perPage): array
    {
        return Cache::remember(
            $this->listKey($page, $perPage),
            self::CACHE_TTL_SECONDS,
            function () use ($page, $perPage): array {
                $posts = Post::query()
                    ->with(['tags', 'author'])
                    ->latest('created_at')
                    ->latest('id')
                    ->paginate(perPage: $perPage, page: $page)
                    ->withPath(route('api.v1.posts.index'));

                return PostResource::collection($posts)->response(request())->getData(true);
            },
        );
    }

    /**
     * Read a single post by slug, serving it from cache whenever one is warm.
     *
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException<Post>
     */
    public function findBySlug(string $slug): array
    {
        return Cache::remember(
            $this->showKey($slug),
            self::CACHE_TTL_SECONDS,
            function () use ($slug): array {
                $post = Post::query()
                    ->with(['tags', 'author'])
                    ->where('slug', $slug)
                    ->firstOrFail();

                return PostResource::make($post)->response(request())->getData(true);
            },
        );
    }

    /**
     * Create a post for the given author, attach its tags, and queue the image work.
     *
     * @param  array{title: string, description: string}  $attributes
     * @param  array<int, string>  $tagNames
     */
    public function create(User $author, array $attributes, ?UploadedFile $image = null, array $tagNames = []): Post
    {
        $imagePath = $image?->store(self::IMAGE_DIRECTORY, self::IMAGE_DISK) ?: null;

        $post = DB::transaction(function () use ($author, $attributes, $imagePath, $tagNames): Post {
            $post = $author->posts()->create([
                ...$attributes,
                'slug' => $this->uniqueSlug($attributes['title']),
                'image_path' => $imagePath,
            ]);

            $post->tags()->sync($this->resolveTagIds($tagNames));

            return $post;
        });

        $this->forget($post);

        if ($imagePath !== null) {
            ProcessPostImage::dispatch($post)->afterCommit();
        }

        return $post->load('tags');
    }

    /**
     * Drop every cache entry that could still be serving a stale view of a post.
     */
    public function forget(Post $post): void
    {
        Cache::forget($this->showKey($post->slug));

        $this->bumpListVersion();
    }

    /**
     * Build the cache key for a page of the listing.
     */
    private function listKey(int $page, int $perPage): string
    {
        return sprintf('posts:list:v%d:page%d:per%d', $this->listVersion(), $page, $perPage);
    }

    /**
     * Build the cache key for a single post.
     */
    private function showKey(string $slug): string
    {
        return 'posts:show:'.$slug;
    }

    /**
     * Get the generation number that listing keys are currently stamped with.
     */
    private function listVersion(): int
    {
        return (int) Cache::rememberForever(self::LIST_VERSION_KEY, fn (): int => 1);
    }

    /**
     * Orphan every cached listing by moving to the next generation.
     */
    private function bumpListVersion(): void
    {
        $this->listVersion();

        if (Cache::increment(self::LIST_VERSION_KEY) === false) {
            Cache::forever(self::LIST_VERSION_KEY, $this->listVersion() + 1);
        }
    }

    /**
     * Turn tag names into ids, reusing tags that already exist.
     *
     * @param  array<int, string>  $tagNames
     * @return array<int, int>
     */
    private function resolveTagIds(array $tagNames): array
    {
        return collect($tagNames)
            ->mapWithKeys(fn (string $name): array => [Str::slug($name) => $name])
            ->reject(fn (string $name, string $slug): bool => $slug === '')
            ->map(fn (string $name, string $slug): int => Tag::query()
                ->firstOrCreate(['slug' => $slug], ['name' => $name])
                ->getKey())
            ->values()
            ->all();
    }

    /**
     * Build a slug for the title that no other post is using yet.
     */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        $suffix = 2;

        while (Post::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
