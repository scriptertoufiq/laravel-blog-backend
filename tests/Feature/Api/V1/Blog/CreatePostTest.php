<?php

use App\Jobs\Blog\ProcessPostImage;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Blog\PostService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake(PostService::IMAGE_DISK);

    $this->author = User::factory()->create();
    Sanctum::actingAs($this->author);
});

it('rejects an unauthenticated request', function (): void {
    startNewRequestLifecycle();

    $this->postJson(route('api.v1.posts.store'), [
        'title' => 'Anonymous Post',
        'description' => 'A description that is comfortably long enough.',
    ])->assertUnauthorized();

    expect(Post::query()->count())->toBe(0);
});

it('lets an author whose email is not verified create a post', function (): void {
    startNewRequestLifecycle();
    $unverified = User::factory()->unverified()->create();
    Sanctum::actingAs($unverified);

    $this->postJson(route('api.v1.posts.store'), [
        'title' => 'Unverified Post',
        'description' => 'A description that is comfortably long enough.',
    ])->assertCreated()->assertJsonPath('data.title', 'Unverified Post');

    expect(Post::query()->sole()->user_id)->toBe($unverified->id);
});

it('creates a post with a title and description', function (): void {
    $response = $this->postJson(route('api.v1.posts.store'), [
        'title' => 'Laravel Queues In Practice',
        'description' => 'Everything I learned shipping background jobs to production.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Laravel Queues In Practice')
        ->assertJsonPath('data.slug', 'laravel-queues-in-practice')
        ->assertJsonPath('data.description', 'Everything I learned shipping background jobs to production.')
        ->assertJsonPath('data.image_url', null)
        ->assertJsonPath('data.tags', [])
        ->assertJsonStructure(['message', 'data' => ['id', 'title', 'slug', 'description', 'created_at']]);

    $this->assertDatabaseHas('posts', [
        'user_id' => $this->author->id,
        'title' => 'Laravel Queues In Practice',
        'slug' => 'laravel-queues-in-practice',
    ]);
});

it('attaches the post to the authenticated author', function (): void {
    $someoneElse = User::factory()->create();

    $this->postJson(route('api.v1.posts.store'), [
        'title' => 'My Post',
        'description' => 'A description that is comfortably long enough.',
    ])->assertCreated();

    expect(Post::query()->sole()->user_id)->toBe($this->author->id)
        ->and($someoneElse->posts()->count())->toBe(0);
});

it('stores an uploaded image and queues the thumbnail work', function (): void {
    $response = $this->post(route('api.v1.posts.store'), [
        'title' => 'Post With A Cover',
        'description' => 'A description that is comfortably long enough.',
        'image' => UploadedFile::fake()->image('cover.jpg', 1200, 800),
    ]);

    $response->assertCreated()->assertJsonPath('data.image_thumbnail_url', null);

    $post = Post::query()->sole();

    expect($post->image_path)->toStartWith('posts/images/')
        ->and($post->image_thumbnail_path)->toBeNull();

    Storage::disk(PostService::IMAGE_DISK)->assertExists($post->image_path);

    expect($response->json('data.image_url'))->toContain($post->image_path);

    Queue::assertPushed(
        ProcessPostImage::class,
        fn (ProcessPostImage $job): bool => $job->post->is($post) && $job->queue === 'images',
    );
});

it('does not queue image work when no image is uploaded', function (): void {
    $this->postJson(route('api.v1.posts.store'), [
        'title' => 'No Cover Here',
        'description' => 'A description that is comfortably long enough.',
    ])->assertCreated();

    Queue::assertNotPushed(ProcessPostImage::class);
});

it('never trusts the uploaded file name', function (): void {
    $this->post(route('api.v1.posts.store'), [
        'title' => 'Sneaky Upload',
        'description' => 'A description that is comfortably long enough.',
        'image' => UploadedFile::fake()->image('../../evil shell.jpg', 400, 400),
    ])->assertCreated();

    $path = Post::query()->sole()->image_path;

    expect($path)->toStartWith('posts/images/')
        ->and($path)->not->toContain('..')
        ->and($path)->not->toContain('evil')
        ->and($path)->not->toContain(' ');
});

it('creates tags and links them to the post', function (): void {
    $this->postJson(route('api.v1.posts.store'), [
        'title' => 'Tagged Post',
        'description' => 'A description that is comfortably long enough.',
        'tags' => ['Laravel', 'Queues'],
    ])->assertCreated()
        ->assertJsonCount(2, 'data.tags')
        ->assertJsonPath('data.tags.0.name', 'Laravel')
        ->assertJsonPath('data.tags.0.slug', 'laravel');

    expect(Tag::query()->count())->toBe(2)
        ->and(Post::query()->sole()->tags->pluck('slug')->sort()->values()->all())
        ->toBe(['laravel', 'queues']);
});

it('reuses an existing tag instead of duplicating it', function (): void {
    $existing = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $this->postJson(route('api.v1.posts.store'), [
        'title' => 'Another Tagged Post',
        'description' => 'A description that is comfortably long enough.',
        'tags' => ['laravel', 'testing'],
    ])->assertCreated();

    expect(Tag::query()->count())->toBe(2)
        ->and(Post::query()->sole()->tags->pluck('id'))->toContain($existing->id);
});

it('treats tags differing only by case or spacing as the same tag', function (): void {
    $this->postJson(route('api.v1.posts.store'), [
        'title' => 'Case Insensitive Tags',
        'description' => 'A description that is comfortably long enough.',
        'tags' => ['Laravel', '  laravel  '],
    ])->assertUnprocessable()->assertJsonValidationErrors('tags.1');
});

it('gives colliding titles distinct slugs', function (): void {
    foreach (range(1, 3) as $attempt) {
        $this->postJson(route('api.v1.posts.store'), [
            'title' => 'The Same Title',
            'description' => 'A description that is comfortably long enough.',
        ])->assertCreated();
    }

    expect(Post::query()->orderBy('id')->pluck('slug')->all())
        ->toBe(['the-same-title', 'the-same-title-2', 'the-same-title-3']);
});

it('validates the post fields', function (array $payload, array $errors): void {
    $this->postJson(route('api.v1.posts.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'nothing supplied' => [[], ['title', 'description']],
    'blank title' => [['title' => '', 'description' => 'A long enough description.'], ['title']],
    'title too short' => [['title' => 'ab', 'description' => 'A long enough description.'], ['title']],
    'title too long' => [['title' => str_repeat('a', 256), 'description' => 'A long enough description.'], ['title']],
    'description too short' => [['title' => 'Valid Title', 'description' => 'short'], ['description']],
    'tags not an array' => [['title' => 'Valid Title', 'description' => 'A long enough description.', 'tags' => 'nope'], ['tags']],
    'too many tags' => [['title' => 'Valid Title', 'description' => 'A long enough description.', 'tags' => range(1, 11)], ['tags']],
    'tag too short' => [['title' => 'Valid Title', 'description' => 'A long enough description.', 'tags' => ['a']], ['tags.0']],
    'blank tag' => [['title' => 'Valid Title', 'description' => 'A long enough description.', 'tags' => ['laravel', '   ']], ['tags.1']],
    'non string tag' => [['title' => 'Valid Title', 'description' => 'A long enough description.', 'tags' => [42]], ['tags.0']],
]);

it('rejects a file that is not an image', function (): void {
    $this->post(route('api.v1.posts.store'), [
        'title' => 'Bad Upload',
        'description' => 'A description that is comfortably long enough.',
        'image' => UploadedFile::fake()->create('payload.php', 20, 'text/x-php'),
    ])->assertUnprocessable()->assertJsonValidationErrors('image');

    expect(Post::query()->count())->toBe(0);
});

it('rejects an svg disguised as an image', function (): void {
    $this->post(route('api.v1.posts.store'), [
        'title' => 'Svg Upload',
        'description' => 'A description that is comfortably long enough.',
        'image' => UploadedFile::fake()->create('vector.svg', 20, 'image/svg+xml'),
    ])->assertUnprocessable()->assertJsonValidationErrors('image');
});

it('rejects an image that is too large', function (): void {
    $this->post(route('api.v1.posts.store'), [
        'title' => 'Huge Upload',
        'description' => 'A description that is comfortably long enough.',
        'image' => UploadedFile::fake()->image('huge.jpg', 3000, 3000)->size(6000),
    ])->assertUnprocessable()->assertJsonValidationErrors('image');
});

it('rejects an image below the minimum dimensions', function (): void {
    $this->post(route('api.v1.posts.store'), [
        'title' => 'Tiny Upload',
        'description' => 'A description that is comfortably long enough.',
        'image' => UploadedFile::fake()->image('tiny.jpg', 50, 50),
    ])->assertUnprocessable()->assertJsonValidationErrors('image');
});

it('trims surrounding whitespace from the title', function (): void {
    $this->postJson(route('api.v1.posts.store'), [
        'title' => "  Spaced   Out   Title \n",
        'description' => 'A description that is comfortably long enough.',
    ])->assertCreated()->assertJsonPath('data.title', 'Spaced Out Title');
});

it('leaves no post behind when tag persistence fails', function (): void {
    Tag::saving(fn (): never => throw new RuntimeException('tag write failed'));

    try {
        $this->postJson(route('api.v1.posts.store'), [
            'title' => 'Doomed Post',
            'description' => 'A description that is comfortably long enough.',
            'tags' => ['laravel'],
        ]);
    } catch (RuntimeException) {
        // The transaction is what matters here, not how the failure surfaces.
    }

    expect(Post::query()->count())->toBe(0);
});
