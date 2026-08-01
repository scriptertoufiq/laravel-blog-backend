<?php

use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Laravel\Sanctum\PersonalAccessToken;

function tokenFor(User $user, string $device = 'test-device'): string
{
    return $user->createToken($device)->plainTextToken;
}

it('revokes the presented token', function (): void {
    $user = User::factory()->create();
    $token = tokenFor($user);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson(route('api.v1.logout'))
        ->assertOk()
        ->assertJsonStructure(['message']);

    expect($user->tokens()->count())->toBe(0)
        ->and(PersonalAccessToken::findToken($token))->toBeNull();
});

it('leaves tokens for other devices intact', function (): void {
    $user = User::factory()->create();
    $phone = tokenFor($user, 'phone');
    $laptop = tokenFor($user, 'laptop');

    $this->withHeader('Authorization', 'Bearer '.$phone)
        ->postJson(route('api.v1.logout'))
        ->assertOk();

    expect($user->tokens()->count())->toBe(1);

    startNewRequestLifecycle();

    $this->withHeader('Authorization', 'Bearer '.$laptop)
        ->getJson(route('api.v1.user'))
        ->assertOk();
});

it('stops the revoked token from being used again', function (): void {
    $user = User::factory()->create();
    $token = tokenFor($user);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson(route('api.v1.logout'))
        ->assertOk();

    startNewRequestLifecycle();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson(route('api.v1.user'))
        ->assertUnauthorized();
});

it('rejects a logout without a token', function (): void {
    $this->postJson(route('api.v1.logout'))->assertUnauthorized();
});

it('rejects a logout with a bogus token', function (): void {
    $this->withHeader('Authorization', 'Bearer not-a-real-token')
        ->postJson(route('api.v1.logout'))
        ->assertUnauthorized();
});

it('lets an unverified token holder read their profile and log out', function (): void {
    $user = User::factory()->unverified()->create();
    $token = tokenFor($user);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson(route('api.v1.user'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email_verified', false);

    startNewRequestLifecycle();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson(route('api.v1.logout'))
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('revokes every token when logging out from all devices', function (): void {
    $user = User::factory()->create();
    tokenFor($user, 'phone');
    tokenFor($user, 'laptop');
    tokenFor($user, 'tablet');

    app(AuthenticationService::class)->logoutFromAllDevices($user);

    expect($user->tokens()->count())->toBe(0);
});

it('requires authentication to read the profile', function (): void {
    $this->getJson(route('api.v1.user'))->assertUnauthorized();
});
