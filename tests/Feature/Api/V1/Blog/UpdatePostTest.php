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
    $this->post = Post::factory()->create([
        'user_id' => $this->author->id,
        'title' => 'Original Title',
        'slug' => 'original-title',
        'description' => 'The description as it was originally written.',
    ]);

    Sanctum::actingAs($this->author);
});

it('updates the title and description', function (): void {
    $this->putJson(route('api.v1.posts.update', $this->post), [
        'title' => 'Revised Title',
        'description' => 'The description after a thorough revision.',
    ])->assertOk()
        ->assertJsonPath('data.title', 'Revised Title')
        ->assertJsonPath('data.description', 'The description after a thorough revision.')
        ->assertJsonStructure(['message', 'data' => ['id', 'title', 'slug', 'description']]);

    $this->assertDatabaseHas('posts', [
        'id' => $this->post->id,
        'title' => 'Revised Title',
        'description' => 'The description after a thorough revision.',
    ]);
});

it('accepts a partial update that changes only the title', function (): void {
    $this->patchJson(route('api.v1.posts.update', $this->post), ['title' => 'Only The Title'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Only The Title')
        ->assertJsonPath('data.description', 'The description as it was originally written.');
});

it('keeps the slug stable so existing links keep working', function (): void {
    $this->putJson(route('api.v1.posts.update', $this->post), ['title' => 'A Completely Different Title'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'original-title');

    $this->getJson(route('api.v1.posts.show', 'original-title'))
        ->assertOk()
        ->assertJsonPath('data.title', 'A Completely Different Title');
});

it('writes the new data straight into the cache', function (): void {
    $this->getJson(route('api.v1.posts.show', 'original-title'))
        ->assertOk()
        ->assertJsonPath('data.title', 'Original Title');

    $this->putJson(route('api.v1.posts.update', $this->post), ['title' => 'Cached Immediately'])->assertOk();

    $queries = postTableQueriesDuring(function (): void {
        $this->getJson(route('api.v1.posts.show', 'original-title'))
            ->assertOk()
            ->assertJsonPath('data.title', 'Cached Immediately');
    });

    expect($queries)->toBe(0);
});

it('refreshes the cached listing after an update', function (): void {
    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Original Title');

    $this->putJson(route('api.v1.posts.update', $this->post), ['title' => 'Listed Anew'])->assertOk();

    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Listed Anew');
});

it('keeps the cached copy correct through the database cache store', function (): void {
    config(['cache.default' => 'database']);

    $this->getJson(route('api.v1.posts.show', 'original-title'))->assertOk();

    $this->putJson(route('api.v1.posts.update', $this->post), ['title' => 'Serialised Update'])->assertOk();

    $queries = postTableQueriesDuring(function (): void {
        $this->getJson(route('api.v1.posts.show', 'original-title'))
            ->assertOk()
            ->assertJsonPath('data.title', 'Serialised Update');
    });

    expect($queries)->toBe(0);
})->group('serialization');

it('replaces the tag set when tags are sent', function (): void {
    $this->post->tags()->attach(Tag::factory()->create(['name' => 'Old', 'slug' => 'old']));

    $this->putJson(route('api.v1.posts.update', $this->post), ['tags' => ['Laravel', 'Caching']])
        ->assertOk()
        ->assertJsonCount(2, 'data.tags');

    expect($this->post->refresh()->tags->pluck('slug')->sort()->values()->all())
        ->toBe(['caching', 'laravel']);
});

it('clears the tags when an empty array is sent', function (): void {
    $this->post->tags()->attach(Tag::factory()->create());

    $this->putJson(route('api.v1.posts.update', $this->post), ['tags' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data.tags');

    expect($this->post->refresh()->tags)->toHaveCount(0);
});

it('leaves the tags alone when the key is omitted', function (): void {
    $this->post->tags()->attach(Tag::factory()->create(['name' => 'Kept', 'slug' => 'kept']));

    $this->putJson(route('api.v1.posts.update', $this->post), ['title' => 'Tags Untouched'])
        ->assertOk()
        ->assertJsonPath('data.tags.0.slug', 'kept');

    expect($this->post->refresh()->tags)->toHaveCount(1);
});

it('reuses existing tags rather than duplicating them', function (): void {
    Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $this->putJson(route('api.v1.posts.update', $this->post), ['tags' => ['laravel']])->assertOk();

    expect(Tag::query()->count())->toBe(1);
});

it('stores a replacement image and queues a fresh thumbnail', function (): void {
    $this->put(route('api.v1.posts.update', $this->post), [
        'image' => UploadedFile::fake()->image('new-cover.jpg', 1200, 800),
    ])->assertOk();

    $this->post->refresh();

    expect($this->post->image_path)->toStartWith('posts/images/')
        ->and($this->post->image_thumbnail_path)->toBeNull();

    Storage::disk(PostService::IMAGE_DISK)->assertExists($this->post->image_path);

    Queue::assertPushed(
        ProcessPostImage::class,
        fn (ProcessPostImage $job): bool => $job->post->is($this->post),
    );
});

it('deletes the old image and thumbnail it replaced', function (): void {
    $disk = Storage::disk(PostService::IMAGE_DISK);
    $oldImage = UploadedFile::fake()->image('old.jpg', 800, 600)
        ->store(PostService::IMAGE_DIRECTORY, PostService::IMAGE_DISK);
    $oldThumbnail = UploadedFile::fake()->image('old-thumb.jpg', 600, 450)
        ->store('posts/thumbnails', PostService::IMAGE_DISK);

    $this->post->forceFill([
        'image_path' => $oldImage,
        'image_thumbnail_path' => $oldThumbnail,
    ])->save();

    $this->put(route('api.v1.posts.update', $this->post), [
        'image' => UploadedFile::fake()->image('new.jpg', 1200, 800),
    ])->assertOk();

    $disk->assertMissing($oldImage);
    $disk->assertMissing($oldThumbnail);
    $disk->assertExists($this->post->refresh()->image_path);
});

it('does not serve the previous thumbnail for a replaced image', function (): void {
    $this->post->forceFill(['image_thumbnail_path' => 'posts/thumbnails/stale.jpg'])->save();

    $this->put(route('api.v1.posts.update', $this->post), [
        'image' => UploadedFile::fake()->image('new.jpg', 1200, 800),
    ])->assertOk()->assertJsonPath('data.image_thumbnail_url', null);
});

it('leaves the image alone when none is sent', function (): void {
    $existing = UploadedFile::fake()->image('keep.jpg', 800, 600)
        ->store(PostService::IMAGE_DIRECTORY, PostService::IMAGE_DISK);

    $this->post->forceFill(['image_path' => $existing])->save();

    $this->putJson(route('api.v1.posts.update', $this->post), ['title' => 'No Image Change'])->assertOk();

    expect($this->post->refresh()->image_path)->toBe($existing);

    Storage::disk(PostService::IMAGE_DISK)->assertExists($existing);
    Queue::assertNotPushed(ProcessPostImage::class);
});

it('refuses to let one author edit another author\'s post', function (): void {
    startNewRequestLifecycle();
    Sanctum::actingAs(User::factory()->create());

    $this->putJson(route('api.v1.posts.update', $this->post), ['title' => 'Hijacked'])
        ->assertForbidden();

    expect($this->post->refresh()->title)->toBe('Original Title');
});

it('rejects an unauthenticated update', function (): void {
    startNewRequestLifecycle();

    $this->putJson(route('api.v1.posts.update', $this->post), ['title' => 'Anonymous Edit'])
        ->assertUnauthorized();

    expect($this->post->refresh()->title)->toBe('Original Title');
});

it('lets an author whose email is not verified edit their own post', function (): void {
    startNewRequestLifecycle();

    $unverified = User::factory()->unverified()->create();
    $theirPost = Post::factory()->create(['user_id' => $unverified->id]);

    Sanctum::actingAs($unverified);

    $this->putJson(route('api.v1.posts.update', $theirPost), ['title' => 'Unverified Edit'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Unverified Edit');
});

it('returns not found for an unknown slug', function (): void {
    $this->putJson(route('api.v1.posts.update', 'no-such-post'), ['title' => 'Ghost Edit'])
        ->assertNotFound();
});

it('validates the fields that are sent', function (array $payload, array $errors): void {
    $this->putJson(route('api.v1.posts.update', $this->post), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'blank title' => [['title' => ''], ['title']],
    'title too short' => [['title' => 'ab'], ['title']],
    'title too long' => [['title' => str_repeat('a', 256)], ['title']],
    'blank description' => [['description' => ''], ['description']],
    'description too short' => [['description' => 'short'], ['description']],
    'tags not an array' => [['tags' => 'nope'], ['tags']],
    'too many tags' => [['tags' => range(1, 11)], ['tags']],
    'duplicate tags' => [['tags' => ['Laravel', 'laravel']], ['tags.1']],
]);

it('rejects a replacement file that is not an image', function (): void {
    $this->put(route('api.v1.posts.update', $this->post), [
        'image' => UploadedFile::fake()->create('payload.php', 20, 'text/x-php'),
    ])->assertUnprocessable()->assertJsonValidationErrors('image');

    expect($this->post->refresh()->image_path)->toBeNull();
});

it('accepts an empty update without changing anything', function (): void {
    $this->putJson(route('api.v1.posts.update', $this->post), [])
        ->assertOk()
        ->assertJsonPath('data.title', 'Original Title')
        ->assertJsonPath('data.description', 'The description as it was originally written.');
});
