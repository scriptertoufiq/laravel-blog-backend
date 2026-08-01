<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Blog\PostService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('lists posts newest first', function (): void {
    $older = Post::factory()->create(['title' => 'Older', 'created_at' => now()->subDay()]);
    $newer = Post::factory()->create(['title' => 'Newer', 'created_at' => now()]);

    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonStructure(['data' => [['id', 'title', 'slug', 'description', 'tags']], 'links', 'meta']);
});

it('is reachable without authentication', function (): void {
    Post::factory()->count(2)->create();

    $this->getJson(route('api.v1.posts.index'))->assertOk()->assertJsonCount(2, 'data');
});

it('serves the second request entirely from cache', function (): void {
    Post::factory()->count(3)->create();

    $first = queriesDuring(fn () => $this->getJson(route('api.v1.posts.index'))->assertOk());
    $second = queriesDuring(fn () => $this->getJson(route('api.v1.posts.index'))->assertOk());

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0);
});

it('returns identical payloads from cache and from the database', function (): void {
    Post::factory()->count(3)->create();

    $fromDatabase = $this->getJson(route('api.v1.posts.index'))->assertOk()->json();
    $fromCache = $this->getJson(route('api.v1.posts.index'))->assertOk()->json();

    expect($fromCache)->toBe($fromDatabase);
});

it('caches each page separately', function (): void {
    Post::factory()->count(30)->create();

    $pageOne = $this->getJson(route('api.v1.posts.index'))->assertOk()->json('data.0.id');
    $pageTwo = $this->getJson(route('api.v1.posts.index', ['page' => 2]))->assertOk()->json('data.0.id');

    expect($pageOne)->not->toBe($pageTwo);

    $queries = queriesDuring(function (): void {
        $this->getJson(route('api.v1.posts.index'))->assertOk();
        $this->getJson(route('api.v1.posts.index', ['page' => 2]))->assertOk();
    });

    expect($queries)->toBe(0);
});

it('caches each page size separately', function (): void {
    Post::factory()->count(20)->create();

    $this->getJson(route('api.v1.posts.index', ['per_page' => 5]))->assertOk()->assertJsonCount(5, 'data');
    $this->getJson(route('api.v1.posts.index', ['per_page' => 10]))->assertOk()->assertJsonCount(10, 'data');
});

it('honours the default page size', function (): void {
    Post::factory()->count(20)->create();

    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.per_page', 15);
});

it('drops the cached listing when a post is created', function (): void {
    Queue::fake();
    Storage::fake(PostService::IMAGE_DISK);

    Post::factory()->count(2)->create();

    $this->getJson(route('api.v1.posts.index'))->assertOk()->assertJsonCount(2, 'data');

    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.posts.store'), [
        'title' => 'Freshly Written',
        'description' => 'A description that is comfortably long enough.',
    ])->assertCreated();

    startNewRequestLifecycle();

    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.title', 'Freshly Written');
});

it('includes tags in the cached payload', function (): void {
    $post = Post::factory()->create();
    $post->tags()->attach(Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']));

    $this->getJson(route('api.v1.posts.index'))->assertOk();

    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.tags.0.slug', 'laravel');
});

it('does not run a query per post when building the listing', function (): void {
    Post::factory()->count(10)->create()->each(
        fn (Post $post) => $post->tags()->attach(Tag::factory()->create()),
    );

    $queries = queriesDuring(fn () => $this->getJson(route('api.v1.posts.index'))->assertOk());

    expect($queries)->toBeLessThanOrEqual(5);
});

it('exposes the author name but never their email address', function (): void {
    $author = User::factory()->create(['name' => 'Toufiq Hasan', 'email' => 'toufiq@example.com']);
    Post::factory()->create(['user_id' => $author->id]);

    $response = $this->getJson(route('api.v1.posts.index'))->assertOk();

    $response->assertJsonPath('data.0.author.name', 'Toufiq Hasan')
        ->assertJsonPath('data.0.author.id', $author->id);

    expect(json_encode($response->json()))->not->toContain('toufiq@example.com')
        ->and(array_keys($response->json('data.0.author')))->toBe(['id', 'name']);
});

it('returns an empty page when there are no posts', function (): void {
    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

it('survives a round trip through the database cache store', function (): void {
    config(['cache.default' => 'database']);

    Post::factory()->count(3)->create()->each(
        fn (Post $post) => $post->tags()->attach(Tag::factory()->create()),
    );

    $fromDatabase = $this->getJson(route('api.v1.posts.index'))->assertOk()->json();

    $postQueries = postTableQueriesDuring(function () use (&$fromCache): void {
        $fromCache = $this->getJson(route('api.v1.posts.index'))->assertOk()->json();
    });

    expect($fromCache)->toBe($fromDatabase)
        ->and($fromCache['data'])->toHaveCount(3)
        ->and($fromCache['data'][0]['tags'])->toHaveCount(1)
        ->and($postQueries)->toBe(0);
})->group('serialization');

it('validates the paging parameters', function (array $query, array $errors): void {
    $this->getJson(route('api.v1.posts.index', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'page zero' => [['page' => 0], ['page']],
    'negative page' => [['page' => -1], ['page']],
    'page not a number' => [['page' => 'abc'], ['page']],
    'page beyond the cap' => [['page' => 1001], ['page']],
    'per page zero' => [['per_page' => 0], ['per_page']],
    'per page beyond the cap' => [['per_page' => 51], ['per_page']],
    'per page not a number' => [['per_page' => 'many'], ['per_page']],
]);
