<?php

use App\Jobs\Blog\ProcessPostImage;
use App\Models\Post;
use App\Models\Tag;
use App\Services\Blog\PostService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('shows a post by slug', function (): void {
    $post = Post::factory()->create(['title' => 'A Readable Post', 'slug' => 'a-readable-post']);

    $this->getJson(route('api.v1.posts.show', 'a-readable-post'))
        ->assertOk()
        ->assertJsonPath('data.id', $post->id)
        ->assertJsonPath('data.title', 'A Readable Post')
        ->assertJsonPath('data.slug', 'a-readable-post')
        ->assertJsonStructure(['data' => ['id', 'title', 'slug', 'description', 'tags', 'created_at']]);
});

it('is reachable without authentication', function (): void {
    Post::factory()->create(['slug' => 'public-post']);

    $this->getJson(route('api.v1.posts.show', 'public-post'))->assertOk();
});

it('serves the second request entirely from cache', function (): void {
    Post::factory()->create(['slug' => 'cached-post']);

    $first = queriesDuring(fn () => $this->getJson(route('api.v1.posts.show', 'cached-post'))->assertOk());
    $second = queriesDuring(fn () => $this->getJson(route('api.v1.posts.show', 'cached-post'))->assertOk());

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0);
});

it('returns identical payloads from cache and from the database', function (): void {
    Post::factory()->create(['slug' => 'stable-post']);

    $fromDatabase = $this->getJson(route('api.v1.posts.show', 'stable-post'))->assertOk()->json();
    $fromCache = $this->getJson(route('api.v1.posts.show', 'stable-post'))->assertOk()->json();

    expect($fromCache)->toBe($fromDatabase);
});

it('includes tags in the cached payload', function (): void {
    $post = Post::factory()->create(['slug' => 'tagged-post']);
    $post->tags()->attach(Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']));

    $this->getJson(route('api.v1.posts.show', 'tagged-post'))->assertOk();

    $this->getJson(route('api.v1.posts.show', 'tagged-post'))
        ->assertOk()
        ->assertJsonPath('data.tags.0.slug', 'laravel');
});

it('caches each post separately', function (): void {
    Post::factory()->create(['slug' => 'first-post', 'title' => 'First']);
    Post::factory()->create(['slug' => 'second-post', 'title' => 'Second']);

    $this->getJson(route('api.v1.posts.show', 'first-post'))->assertOk()->assertJsonPath('data.title', 'First');

    $this->getJson(route('api.v1.posts.show', 'second-post'))
        ->assertOk()
        ->assertJsonPath('data.title', 'Second');
});

it('survives a round trip through the database cache store', function (): void {
    config(['cache.default' => 'database']);

    $post = Post::factory()->create(['slug' => 'serialised-post']);
    $post->tags()->attach(Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']));

    $fromDatabase = $this->getJson(route('api.v1.posts.show', 'serialised-post'))->assertOk()->json();

    $postQueries = postTableQueriesDuring(function () use (&$fromCache): void {
        $fromCache = $this->getJson(route('api.v1.posts.show', 'serialised-post'))->assertOk()->json();
    });

    expect($fromCache)->toBe($fromDatabase)
        ->and($fromCache['data']['tags'][0]['slug'])->toBe('laravel')
        ->and($postQueries)->toBe(0);
})->group('serialization');

it('returns not found for an unknown slug', function (): void {
    $this->getJson(route('api.v1.posts.show', 'no-such-post'))->assertNotFound();
});

it('does not cache a miss, so a later post becomes visible', function (): void {
    $this->getJson(route('api.v1.posts.show', 'arriving-later'))->assertNotFound();

    Post::factory()->create(['slug' => 'arriving-later', 'title' => 'Arrived']);

    $this->getJson(route('api.v1.posts.show', 'arriving-later'))
        ->assertOk()
        ->assertJsonPath('data.title', 'Arrived');
});

/**
 * Store a real image on the fake disk and return a post pointing at it.
 *
 * The queue is deliberately left un-faked in these tests: `Queue::fake()` also
 * intercepts `dispatch_sync`, so the job under test would never run.
 */
function postAwaitingThumbnail(): Post
{
    Storage::fake(PostService::IMAGE_DISK);

    $path = UploadedFile::fake()
        ->image('cover.jpg', 1200, 800)
        ->store(PostService::IMAGE_DIRECTORY, PostService::IMAGE_DISK);

    return Post::factory()->create(['image_path' => $path]);
}

it('refreshes the cached post once its thumbnail is generated', function (): void {
    $post = postAwaitingThumbnail();

    $this->getJson(route('api.v1.posts.show', $post->slug))
        ->assertOk()
        ->assertJsonPath('data.image_thumbnail_url', null);

    dispatch_sync(new ProcessPostImage($post));

    expect($post->fresh()->image_thumbnail_path)->not->toBeNull();

    $this->getJson(route('api.v1.posts.show', $post->slug))
        ->assertOk()
        ->assertJsonPath('data.image_thumbnail_url', fn (?string $url): bool => $url !== null);
});

it('refreshes the cached listing once a thumbnail is generated', function (): void {
    $post = postAwaitingThumbnail();

    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.image_thumbnail_url', null);

    dispatch_sync(new ProcessPostImage($post));

    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.image_thumbnail_url', fn (?string $url): bool => $url !== null);
});
