<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Blog\PostService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fills the blog with believable filler posts.
 *
 * Rows are bulk inserted in chunks rather than saved one model at a time —
 * a thousand `Post::create()` calls would be a thousand round trips.
 *
 * Usage: `php artisan db:seed --class=PostSeeder`
 *        `POSTS=250 php artisan db:seed --class=PostSeeder`
 */
class PostSeeder extends Seeder
{
    private const DEFAULT_COUNT = 1000;

    private const CHUNK = 200;

    /** Roughly what share of posts get a cover image. */
    private const IMAGE_SHARE = 0.4;

    /** @var array<int, string> */
    private const TOPICS = [
        'laravel', 'react', 'caching', 'queues', 'testing', 'security', 'eloquent',
        'performance', 'typescript', 'tailwind', 'devops', 'databases', 'api-design',
        'refactoring', 'observability',
    ];

    /** @var array<int, string> */
    private const TITLE_PATTERNS = [
        'Why :subject Breaks Under Load',
        'A Practical Guide to :subject',
        'What Nobody Tells You About :subject',
        ':subject Without the Boilerplate',
        'Rethinking :subject in Production',
        'The Hidden Cost of :subject',
        'Debugging :subject at Three in the Morning',
        'From Prototype to Production with :subject',
        ':subject, Measured Rather Than Guessed',
        'Stop Reaching For :subject By Default',
        'Everything I Got Wrong About :subject',
        'Shipping :subject Without Downtime',
    ];

    /** @var array<int, string> */
    private const SUBJECTS = [
        'Queue Workers', 'Cache Invalidation', 'Eager Loading', 'Database Indexes',
        'Rate Limiting', 'Form Requests', 'Service Containers', 'Event Listeners',
        'Model Factories', 'Signed URLs', 'File Uploads', 'Bearer Tokens',
        'Pagination', 'Soft Deletes', 'Feature Flags', 'Background Jobs',
        'Type Safety', 'Component State', 'Optimistic Updates', 'Error Boundaries',
    ];

    /** @var array<int, string> */
    private const OPENERS = [
        'It worked fine with a hundred rows. At ten thousand it fell over, and the profiler pointed somewhere nobody expected.',
        'The fix took one line. Finding out which line took most of a week, and the search is the part worth writing down.',
        'Every tutorial shows the happy path. Production is mostly the other paths, so this is about those.',
        'There is a default here that is right almost all of the time. This is about the times it is not.',
        'The symptom looked like a caching bug. It was not a caching bug, and the difference matters.',
        'Two approaches look equivalent on paper. Under concurrency only one of them survives.',
    ];

    /** @var array<int, string> */
    private const BODIES = [
        'The tempting move is to reach for the abstraction immediately. Resist it long enough to measure — half the time the straightforward version is fast enough, and the other half the measurement tells you which part actually needs the work.',
        'What made this hard to spot is that the test suite passed. The test environment used a different driver, and that driver happened to paper over exactly the behaviour that broke in production.',
        'Keep the boundary narrow. Once the helper starts taking a flag argument to change what it returns, it has stopped being one thing and should be split before it grows a second flag.',
        'The ordering guarantee you assume is rarely the one the database gives you. Without an explicit sort, row order is undefined, and it will stay stable right up until the day it does not.',
        'Retries are not free. A job that fails fast and retries immediately can turn a brief outage into a sustained hammering of whatever was already struggling. Back off exponentially.',
    ];

    /** @var array<int, string> */
    private const CLOSERS = [
        'None of this is exotic. It is just the kind of thing you only learn by watching it fail once.',
        'The general lesson: measure before optimising, and be suspicious of any test that passes for a reason you cannot articulate.',
        'If you take one thing from this, make it the habit of checking what your framework actually does rather than what you assume it does.',
    ];

    public function run(): void
    {
        $count = max(1, (int) (env('POSTS') ?? self::DEFAULT_COUNT));

        $authorIds = $this->authorIds();
        $tagIds = $this->tagIds();
        $images = $this->reusableImages();
        $existingSlugs = Post::query()->pluck('slug')->flip();

        $this->command?->info("Seeding {$count} posts…");
        $bar = $this->command?->getOutput()->createProgressBar($count);

        $created = 0;

        foreach (array_chunk(range(1, $count), self::CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $index) {
                $title = $this->title();
                $slug = $this->uniqueSlug($title, $index, $existingSlugs);
                $createdAt = now()->subMinutes(random_int(0, 60 * 24 * 365));
                $image = $images === [] || mt_rand() / mt_getrandmax() > self::IMAGE_SHARE
                    ? null
                    : $images[array_rand($images)];

                $rows[] = [
                    'user_id' => $authorIds[array_rand($authorIds)],
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $this->body(),
                    'image_path' => $image['image_path'] ?? null,
                    'image_thumbnail_path' => $image['image_thumbnail_path'] ?? null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            DB::transaction(function () use ($rows, $tagIds): void {
                Post::query()->insert($rows);

                $slugs = array_column($rows, 'slug');
                $ids = Post::query()->whereIn('slug', $slugs)->pluck('id');

                $pivot = [];

                foreach ($ids as $postId) {
                    foreach ((array) array_rand(array_flip($tagIds), random_int(1, 3)) as $tagId) {
                        $pivot[] = ['post_id' => $postId, 'tag_id' => $tagId];
                    }
                }

                DB::table('post_tag')->insertOrIgnore($pivot);
            });

            $created += count($rows);
            $bar?->advance(count($rows));
        }

        $bar?->finish();
        $this->command?->newLine(2);

        // Every cached listing page is now wrong; drop them in one write.
        app(PostService::class)->forget(Post::query()->latest('id')->firstOrFail());

        $this->command?->info("Done. {$created} posts created, {$this->total()} in total.");
    }

    /**
     * Authors to spread the posts across, creating a few if the table is bare.
     *
     * @return array<int, int>
     */
    private function authorIds(): array
    {
        if (User::query()->count() === 0) {
            User::factory()->count(5)->create();
        }

        /** @var array<int, int> */
        return User::query()->pluck('id')->all();
    }

    /**
     * The tag pool, created once and reused.
     *
     * @return array<int, int>
     */
    private function tagIds(): array
    {
        foreach (self::TOPICS as $topic) {
            Tag::query()->firstOrCreate(
                ['slug' => Str::slug($topic)],
                ['name' => Str::headline($topic)],
            );
        }

        /** @var array<int, int> */
        return Tag::query()->whereIn('slug', array_map(Str::slug(...), self::TOPICS))->pluck('id')->all();
    }

    /**
     * Cover images already on disk, so seeded posts show a real picture rather
     * than a broken one.
     *
     * @return array<int, array{image_path: string, image_thumbnail_path: string|null}>
     */
    private function reusableImages(): array
    {
        $disk = Storage::disk(PostService::IMAGE_DISK);

        return Post::query()
            ->whereNotNull('image_path')
            ->get(['image_path', 'image_thumbnail_path'])
            ->filter(fn (Post $post): bool => $disk->exists((string) $post->image_path))
            ->map(fn (Post $post): array => [
                'image_path' => (string) $post->image_path,
                'image_thumbnail_path' => $post->image_thumbnail_path,
            ])
            ->values()
            ->all();
    }

    private function title(): string
    {
        return str_replace(
            ':subject',
            self::SUBJECTS[array_rand(self::SUBJECTS)],
            self::TITLE_PATTERNS[array_rand(self::TITLE_PATTERNS)],
        );
    }

    private function body(): string
    {
        $paragraphs = [self::OPENERS[array_rand(self::OPENERS)]];

        foreach ((array) array_rand(array_flip(self::BODIES), random_int(1, 3)) as $paragraph) {
            $paragraphs[] = $paragraph;
        }

        $paragraphs[] = self::CLOSERS[array_rand(self::CLOSERS)];

        return implode("\n\n", $paragraphs);
    }

    /**
     * Titles repeat by design, so the index keeps the slug column unique.
     *
     * @param  Collection<string, int>  $taken
     */
    private function uniqueSlug(string $title, int $index, $taken): string
    {
        $slug = Str::slug($title).'-'.$index;

        while ($taken->has($slug)) {
            $slug = Str::slug($title).'-'.$index.'-'.Str::lower(Str::random(4));
        }

        $taken[$slug] = true;

        return $slug;
    }

    private function total(): int
    {
        return Post::query()->count();
    }
}
