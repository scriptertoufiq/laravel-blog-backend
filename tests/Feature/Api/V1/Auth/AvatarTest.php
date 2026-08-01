<?php

use App\Jobs\Auth\ProcessUserAvatar;
use App\Jobs\Blog\RefreshAuthorPostsCache;
use App\Models\Post;
use App\Models\User;
use App\Services\Auth\ProfileService;
use App\Services\Media\ImageResizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake(ProfileService::AVATAR_DISK);

    $this->user = User::factory()->create(['name' => 'Avatar Owner']);
    Sanctum::actingAs($this->user);
});

/** Store a real avatar on the fake disk and attach it to the user. */
function giveUserAvatar(User $user, int $w = 800, int $h = 800): string
{
    $path = UploadedFile::fake()->image('old.jpg', $w, $h)
        ->store(ProfileService::AVATAR_DIRECTORY, ProfileService::AVATAR_DISK);

    $user->forceFill(['avatar_path' => $path])->save();

    return $path;
}

/* --------------------------------------------------------------- upload -- */

it('uploads an avatar', function (): void {
    $response = $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
    ]);

    $response->assertOk();

    $this->user->refresh();

    expect($this->user->avatar_path)->toStartWith('avatars/')
        ->and($this->user->avatar_thumbnail_path)->toBeNull();

    Storage::disk(ProfileService::AVATAR_DISK)->assertExists($this->user->avatar_path);

    expect($response->json('data.avatar_url'))->toContain($this->user->avatar_path)
        ->and($response->json('data.avatar_thumbnail_url'))->toBeNull();
});

it('queues thumbnail generation on the images queue', function (): void {
    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
    ])->assertOk();

    Queue::assertPushed(
        ProcessUserAvatar::class,
        fn (ProcessUserAvatar $job): bool => $job->user->is($this->user) && $job->queue === 'images',
    );
});

it('updates the name and the avatar in one request', function (): void {
    $this->put(route('api.v1.profile.update'), [
        'name' => 'Renamed With Avatar',
        'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
    ])->assertOk()->assertJsonPath('data.name', 'Renamed With Avatar');

    expect($this->user->refresh()->avatar_path)->not->toBeNull();
});

it('never trusts the uploaded file name', function (): void {
    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->image('../../evil shell.jpg', 400, 400),
    ])->assertOk();

    $path = $this->user->refresh()->avatar_path;

    expect($path)->toStartWith('avatars/')
        ->and($path)->not->toContain('..')
        ->and($path)->not->toContain('evil')
        ->and($path)->not->toContain(' ');
});

/* -------------------------------------------------------------- replace -- */

it('deletes the previous avatar and thumbnail when replaced', function (): void {
    $disk = Storage::disk(ProfileService::AVATAR_DISK);
    $oldAvatar = giveUserAvatar($this->user);
    $oldThumb = UploadedFile::fake()->image('old-thumb.jpg', 200, 200)
        ->store(ProfileService::AVATAR_THUMBNAIL_DIRECTORY, ProfileService::AVATAR_DISK);
    $this->user->forceFill(['avatar_thumbnail_path' => $oldThumb])->save();

    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->image('new.jpg', 500, 500),
    ])->assertOk();

    $disk->assertMissing($oldAvatar);
    $disk->assertMissing($oldThumb);
    $disk->assertExists($this->user->refresh()->avatar_path);
});

it('clears the stale thumbnail until the new one is generated', function (): void {
    giveUserAvatar($this->user);
    $this->user->forceFill(['avatar_thumbnail_path' => 'avatars/thumbnails/stale.jpg'])->save();

    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->image('new.jpg', 500, 500),
    ])->assertOk()->assertJsonPath('data.avatar_thumbnail_url', null);
});

/* --------------------------------------------------------------- remove -- */

it('removes the avatar when asked', function (): void {
    $disk = Storage::disk(ProfileService::AVATAR_DISK);
    $old = giveUserAvatar($this->user);

    $this->putJson(route('api.v1.profile.update'), ['remove_avatar' => true])
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null)
        ->assertJsonPath('data.avatar_thumbnail_url', null);

    expect($this->user->refresh()->avatar_path)->toBeNull();
    $disk->assertMissing($old);
});

it('refreshes cached bylines when the avatar is removed', function (): void {
    giveUserAvatar($this->user);

    $this->putJson(route('api.v1.profile.update'), ['remove_avatar' => true])->assertOk();

    Queue::assertPushed(RefreshAuthorPostsCache::class);
});

it('is a harmless no-op when removing with no avatar set', function (): void {
    $this->putJson(route('api.v1.profile.update'), ['remove_avatar' => true])
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    Queue::assertNotPushed(RefreshAuthorPostsCache::class);
});

it('leaves the avatar alone when neither field is sent', function (): void {
    $existing = giveUserAvatar($this->user);

    $this->putJson(route('api.v1.profile.update'), ['name' => 'Just A Rename'])->assertOk();

    expect($this->user->refresh()->avatar_path)->toBe($existing);
    Storage::disk(ProfileService::AVATAR_DISK)->assertExists($existing);
    Queue::assertNotPushed(ProcessUserAvatar::class);
});

/* ------------------------------------------------------------ validation -- */

it('rejects a file that is not an image', function (): void {
    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->create('payload.php', 20, 'text/x-php'),
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');

    expect($this->user->refresh()->avatar_path)->toBeNull();
});

it('rejects an svg', function (): void {
    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->create('vector.svg', 20, 'image/svg+xml'),
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');
});

it('rejects an avatar over the size limit', function (): void {
    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->image('huge.jpg', 3000, 3000)->size(3000),
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');
});

it('rejects an avatar below the minimum dimensions', function (): void {
    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->image('tiny.jpg', 50, 50),
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');
});

it('rejects sending a file and a removal flag together', function (): void {
    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
        'remove_avatar' => '1',
    ])->assertUnprocessable()->assertJsonValidationErrors('remove_avatar');
});

it('rejects an unauthenticated avatar upload', function (): void {
    startNewRequestLifecycle();

    $this->put(route('api.v1.profile.update'), [
        'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
    ])->assertUnauthorized();
});

/* ------------------------------------------------------------------- job -- */

it('generates a thumbnail scaled to 200px', function (): void {
    giveUserAvatar($this->user, 900, 600);

    (new ProcessUserAvatar($this->user))->handle(app(ImageResizer::class));

    $this->user->refresh();

    expect($this->user->avatar_thumbnail_path)->toStartWith('avatars/thumbnails/');

    $bytes = (string) Storage::disk(ProfileService::AVATAR_DISK)->get($this->user->avatar_thumbnail_path);
    $info = getimagesizefromstring($bytes);

    expect([$info[0], $info[1]])->toBe([200, 133]);
});

it('never enlarges a small avatar', function (): void {
    giveUserAvatar($this->user, 120, 120);

    (new ProcessUserAvatar($this->user))->handle(app(ImageResizer::class));

    $bytes = (string) Storage::disk(ProfileService::AVATAR_DISK)
        ->get($this->user->refresh()->avatar_thumbnail_path);
    $info = getimagesizefromstring($bytes);

    expect([$info[0], $info[1]])->toBe([120, 120]);
});

it('keeps the original avatar untouched', function (): void {
    $path = giveUserAvatar($this->user, 900, 600);
    $original = Storage::disk(ProfileService::AVATAR_DISK)->get($path);

    (new ProcessUserAvatar($this->user))->handle(app(ImageResizer::class));

    expect(Storage::disk(ProfileService::AVATAR_DISK)->get($path))->toBe($original);
});

it('does nothing when the user has no avatar', function (): void {
    (new ProcessUserAvatar($this->user))->handle(app(ImageResizer::class));

    expect($this->user->refresh()->avatar_thumbnail_path)->toBeNull();
});

it('leaves the user alone when the avatar file has vanished', function (): void {
    $path = giveUserAvatar($this->user);
    Storage::disk(ProfileService::AVATAR_DISK)->delete($path);

    (new ProcessUserAvatar($this->user))->handle(app(ImageResizer::class));

    expect($this->user->refresh()->avatar_thumbnail_path)->toBeNull();
});

it('leaves the user alone when the file cannot be decoded', function (): void {
    $path = giveUserAvatar($this->user);
    Storage::disk(ProfileService::AVATAR_DISK)->put($path, 'this is not an image');

    (new ProcessUserAvatar($this->user))->handle(app(ImageResizer::class));

    expect($this->user->refresh()->avatar_thumbnail_path)->toBeNull();
});

/* ------------------------------------------------------- author bylines -- */

it('shows the avatar on a post byline', function (): void {
    Post::factory()->create(['user_id' => $this->user->id, 'slug' => 'a-post']);
    giveUserAvatar($this->user);

    (new ProcessUserAvatar($this->user))->handle(app(ImageResizer::class));

    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.author.name', 'Avatar Owner')
        ->assertJsonPath(
            'data.author.avatar_url',
            fn (?string $url): bool => $url !== null && str_contains($url, 'avatars/thumbnails/'),
        );
});

it('falls back to the full avatar before a thumbnail exists', function (): void {
    Post::factory()->create(['user_id' => $this->user->id, 'slug' => 'a-post']);
    $path = giveUserAvatar($this->user);

    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath(
            'data.author.avatar_url',
            fn (?string $url): bool => $url !== null && str_contains($url, $path),
        );
});

it('reports a null avatar for a user without one', function (): void {
    Post::factory()->create(['user_id' => $this->user->id, 'slug' => 'a-post']);

    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.author.avatar_url', null);
});

it('still never exposes the author email alongside the avatar', function (): void {
    $author = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    Post::factory()->create(['user_id' => $author->id]);

    $response = $this->getJson(route('api.v1.posts.index'))->assertOk();

    expect(json_encode($response->json()))->not->toContain('ada@example.com')
        ->and(array_keys($response->json('data.0.author')))->toBe(['id', 'name', 'avatar_url']);
});
