<?php

use App\Jobs\Auth\SendEmailVerificationLink;
use App\Jobs\Blog\RefreshAuthorPostsCache;
use App\Models\Post;
use App\Models\User;
use App\Services\Blog\PostService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Queue::fake();

    $this->user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
        'password' => 'password-secret',
    ]);
});

/**
 * Authenticate with a real bearer token rather than `Sanctum::actingAs`, so
 * `currentAccessToken()` is populated the way a browser request populates it.
 */
function actingWithToken(User $user, string $device = 'test-device'): string
{
    $token = $user->createToken($device)->plainTextToken;

    test()->withHeader('Authorization', 'Bearer '.$token);

    return $token;
}

/* --------------------------------------------------------------- profile -- */

it('updates the name', function (): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), ['name' => 'Updated Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonPath('message', 'Profile updated.');

    expect($this->user->refresh()->name)->toBe('Updated Name');
});

it('updates the email and resets verification', function (): void {
    Sanctum::actingAs($this->user);

    expect($this->user->hasVerifiedEmail())->toBeTrue();

    $this->putJson(route('api.v1.profile.update'), ['email' => 'new@example.com'])
        ->assertOk()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('message', 'Profile updated. Please verify your new email address.');

    expect($this->user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('queues a verification link when the email changes', function (): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), ['email' => 'new@example.com'])->assertOk();

    Queue::assertPushed(
        SendEmailVerificationLink::class,
        fn (SendEmailVerificationLink $job): bool => $job->user->is($this->user),
    );
});

it('does not touch verification when the email is unchanged', function (): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), [
        'name' => 'Renamed',
        'email' => 'original@example.com',
    ])->assertOk()->assertJsonPath('data.email_verified', true);

    expect($this->user->refresh()->hasVerifiedEmail())->toBeTrue();
    Queue::assertNotPushed(SendEmailVerificationLink::class);
});

it('accepts a partial update that changes only one field', function (): void {
    Sanctum::actingAs($this->user);

    $this->patchJson(route('api.v1.profile.update'), ['name' => 'Only Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Only Name')
        ->assertJsonPath('data.email', 'original@example.com');
});

it('normalises the name and email before saving', function (): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), [
        'name' => "  Spaced   Out  \n",
        'email' => '  NEW@Example.COM ',
    ])->assertOk();

    $this->assertDatabaseHas('users', [
        'id' => $this->user->id,
        'name' => 'Spaced Out',
        'email' => 'new@example.com',
    ]);
});

it('lets the user keep their own email address', function (): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), ['email' => 'original@example.com'])
        ->assertOk()
        ->assertJsonPath('data.email', 'original@example.com');
});

it('rejects an email another account already uses', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), ['email' => 'taken@example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect($this->user->refresh()->email)->toBe('original@example.com');
});

it('validates the profile fields', function (array $payload, array $errors): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'blank name' => [['name' => ''], ['name']],
    'name too short' => [['name' => 'a'], ['name']],
    'name too long' => [['name' => str_repeat('a', 256)], ['name']],
    'blank email' => [['email' => ''], ['email']],
    'malformed email' => [['email' => 'not-an-email'], ['email']],
]);

it('rejects an unauthenticated profile update', function (): void {
    startNewRequestLifecycle();

    $this->putJson(route('api.v1.profile.update'), ['name' => 'Anonymous'])->assertUnauthorized();

    expect($this->user->refresh()->name)->toBe('Original Name');
});

it('accepts an empty update without changing anything', function (): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), [])
        ->assertOk()
        ->assertJsonPath('data.name', 'Original Name')
        ->assertJsonPath('data.email', 'original@example.com');
});

/* ----------------------------------------------------- cached post bylines -- */

it('queues a cache refresh when the name changes', function (): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), ['name' => 'Renamed Author'])->assertOk();

    Queue::assertPushed(
        RefreshAuthorPostsCache::class,
        fn (RefreshAuthorPostsCache $job): bool => $job->author->is($this->user) && $job->queue === 'cache',
    );
});

it('does not queue a cache refresh when the name is unchanged', function (): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.profile.update'), ['name' => 'Original Name'])->assertOk();

    Queue::assertNotPushed(RefreshAuthorPostsCache::class);
});

it('clears stale bylines from cached posts when the job runs', function (): void {
    $post = Post::factory()->create(['user_id' => $this->user->id, 'slug' => 'a-post']);

    // Warm both the single-post and listing caches with the old name.
    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.author.name', 'Original Name');
    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.author.name', 'Original Name');

    $this->user->forceFill(['name' => 'Renamed Author'])->save();

    // `handle()` is called directly with its dependency: `Queue::fake()` in the
    // beforeEach also intercepts `dispatch_sync`, so the job would never run.
    (new RefreshAuthorPostsCache($this->user))->handle(app(PostService::class));

    $this->getJson(route('api.v1.posts.show', 'a-post'))
        ->assertOk()
        ->assertJsonPath('data.author.name', 'Renamed Author');
    $this->getJson(route('api.v1.posts.index'))
        ->assertOk()
        ->assertJsonPath('data.0.author.name', 'Renamed Author');

    expect($post->fresh())->not->toBeNull();
});

/* -------------------------------------------------------------- password -- */

it('updates the password', function (): void {
    actingWithToken($this->user);

    $this->putJson(route('api.v1.password.update'), [
        'current_password' => 'password-secret',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertOk()->assertJsonStructure(['message', 'data']);

    expect(Hash::check('brand-new-secret', $this->user->refresh()->password))->toBeTrue();
});

it('never returns the password in the response', function (): void {
    actingWithToken($this->user);

    $response = $this->putJson(route('api.v1.password.update'), [
        'current_password' => 'password-secret',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertOk();

    expect(json_encode($response->json()))
        ->not->toContain('brand-new-secret')
        ->and($response->json('data.password'))->toBeNull();
});

it('rejects a wrong current password', function (): void {
    actingWithToken($this->user);

    $this->putJson(route('api.v1.password.update'), [
        'current_password' => 'not-my-password',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect(Hash::check('password-secret', $this->user->refresh()->password))->toBeTrue();
});

it('rejects a new password identical to the current one', function (): void {
    actingWithToken($this->user);

    $this->putJson(route('api.v1.password.update'), [
        'current_password' => 'password-secret',
        'password' => 'password-secret',
        'password_confirmation' => 'password-secret',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('requires the new password to be confirmed', function (): void {
    actingWithToken($this->user);

    $this->putJson(route('api.v1.password.update'), [
        'current_password' => 'password-secret',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'something-else',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('signs other devices out but keeps the current session alive', function (): void {
    $phone = $this->user->createToken('phone')->plainTextToken;
    $laptop = actingWithToken($this->user, 'laptop');

    expect($this->user->tokens()->count())->toBe(2);

    $this->putJson(route('api.v1.password.update'), [
        'current_password' => 'password-secret',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertOk();

    expect($this->user->tokens()->count())->toBe(1);

    startNewRequestLifecycle();
    $this->withHeader('Authorization', 'Bearer '.$laptop)
        ->getJson(route('api.v1.user'))
        ->assertOk();

    startNewRequestLifecycle();
    $this->withHeader('Authorization', 'Bearer '.$phone)
        ->getJson(route('api.v1.user'))
        ->assertUnauthorized();
});

it('rejects an unauthenticated password change', function (): void {
    startNewRequestLifecycle();

    $this->putJson(route('api.v1.password.update'), [
        'current_password' => 'password-secret',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertUnauthorized();
});

it('throttles repeated password-change attempts', function (): void {
    actingWithToken($this->user);

    foreach (range(1, 5) as $attempt) {
        $this->putJson(route('api.v1.password.update'), [
            'current_password' => 'wrong',
            'password' => 'brand-new-secret',
            'password_confirmation' => 'brand-new-secret',
        ])->assertUnprocessable();
    }

    $this->putJson(route('api.v1.password.update'), [
        'current_password' => 'password-secret',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ])->assertTooManyRequests();
});
