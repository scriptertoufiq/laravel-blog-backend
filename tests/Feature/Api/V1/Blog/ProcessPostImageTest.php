<?php

use App\Jobs\Blog\ProcessPostImage;
use App\Models\Post;
use App\Models\User;
use App\Services\Blog\PostService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake(PostService::IMAGE_DISK);
});

it('turns an uploaded cover into a thumbnail end to end', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->post(route('api.v1.posts.store'), [
        'title' => 'End To End Cover',
        'description' => 'A description that is comfortably long enough.',
        'image' => UploadedFile::fake()->image('cover.jpg', 1500, 1000),
    ])->assertCreated();

    $post = Post::query()->sole();

    expect($post->image_thumbnail_path)->not->toBeNull();

    Storage::disk(PostService::IMAGE_DISK)->assertExists($post->image_thumbnail_path);

    expect(dimensionsOf($post->image_thumbnail_path))->toBe([600, 400]);
});

/**
 * Store a real generated image on the fake disk and return the post carrying it.
 */
function postWithStoredImage(string $name, int $width, int $height): Post
{
    $path = UploadedFile::fake()
        ->image($name, $width, $height)
        ->store(PostService::IMAGE_DIRECTORY, PostService::IMAGE_DISK);

    return Post::factory()->create(['image_path' => $path]);
}

/**
 * Read the pixel dimensions of a file on the image disk.
 *
 * @return array{0: int, 1: int}
 */
function dimensionsOf(string $path): array
{
    $info = getimagesizefromstring((string) Storage::disk(PostService::IMAGE_DISK)->get($path));

    return [$info[0], $info[1]];
}

it('writes a thumbnail scaled down to the maximum width', function (): void {
    $post = postWithStoredImage('cover.jpg', 1800, 1200);

    (new ProcessPostImage($post))->handle();

    $post->refresh();

    expect($post->image_thumbnail_path)->toStartWith('posts/thumbnails/');

    Storage::disk(PostService::IMAGE_DISK)->assertExists($post->image_thumbnail_path);

    expect(dimensionsOf($post->image_thumbnail_path))->toBe([600, 400]);
});

it('preserves the aspect ratio of a portrait image', function (): void {
    $post = postWithStoredImage('tall.jpg', 900, 1800);

    (new ProcessPostImage($post))->handle();

    expect(dimensionsOf($post->refresh()->image_thumbnail_path))->toBe([600, 1200]);
});

it('never enlarges an image that is already small', function (): void {
    $post = postWithStoredImage('small.jpg', 320, 240);

    (new ProcessPostImage($post))->handle();

    expect(dimensionsOf($post->refresh()->image_thumbnail_path))->toBe([320, 240]);
});

it('keeps the original file untouched', function (): void {
    $post = postWithStoredImage('cover.jpg', 1800, 1200);
    $originalBytes = Storage::disk(PostService::IMAGE_DISK)->get($post->image_path);

    (new ProcessPostImage($post))->handle();

    expect(Storage::disk(PostService::IMAGE_DISK)->get($post->image_path))->toBe($originalBytes)
        ->and(dimensionsOf($post->image_path))->toBe([1800, 1200]);
});

it('writes a png thumbnail for a png original', function (): void {
    $post = postWithStoredImage('cover.png', 1000, 1000);

    (new ProcessPostImage($post))->handle();

    $bytes = (string) Storage::disk(PostService::IMAGE_DISK)->get($post->refresh()->image_thumbnail_path);

    expect(getimagesizefromstring($bytes)['mime'])->toBe('image/png');
});

it('does not present thumbnail generation as an edit to the post', function (): void {
    $post = postWithStoredImage('cover.jpg', 1000, 800);
    $updatedAt = $post->updated_at;

    $this->travel(10)->minutes();

    (new ProcessPostImage($post))->handle();

    expect($post->refresh()->updated_at->timestamp)->toBe($updatedAt->timestamp);
});

it('does nothing for a post with no image', function (): void {
    $post = Post::factory()->create(['image_path' => null]);

    (new ProcessPostImage($post))->handle();

    expect($post->refresh()->image_thumbnail_path)->toBeNull();
});

it('leaves the post alone when the source file has vanished', function (): void {
    $post = postWithStoredImage('cover.jpg', 1000, 800);

    Storage::disk(PostService::IMAGE_DISK)->delete($post->image_path);

    (new ProcessPostImage($post))->handle();

    expect($post->refresh()->image_thumbnail_path)->toBeNull();
});

it('leaves the post alone when the file cannot be decoded', function (): void {
    $post = postWithStoredImage('cover.jpg', 1000, 800);

    Storage::disk(PostService::IMAGE_DISK)->put($post->image_path, 'this is not an image');

    (new ProcessPostImage($post))->handle();

    expect($post->refresh()->image_thumbnail_path)->toBeNull();
});

it('can run twice without breaking', function (): void {
    $post = postWithStoredImage('cover.jpg', 1200, 900);

    (new ProcessPostImage($post))->handle();
    $firstPath = $post->refresh()->image_thumbnail_path;

    (new ProcessPostImage($post))->handle();

    expect($post->refresh()->image_thumbnail_path)->toBe($firstPath)
        ->and(dimensionsOf($firstPath))->toBe([600, 450]);
});
