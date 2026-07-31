<?php

namespace App\Services\Blog;

use App\Jobs\Blog\ProcessPostImage;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

        if ($imagePath !== null) {
            ProcessPostImage::dispatch($post)->afterCommit();
        }

        return $post->load('tags');
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
