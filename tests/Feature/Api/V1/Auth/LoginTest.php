<?php

use App\Jobs\Auth\RecordUserLogin;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    Queue::fake();
});

it('issues a token for valid credentials', function (): void {
    $user = User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $response = $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
        'device_name' => 'iphone-15',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'toufiq@example.com')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonStructure(['message', 'token', 'token_type', 'data' => ['id', 'name', 'email']]);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'tokenable_type' => User::class,
        'name' => 'iphone-15',
    ]);
});

it('issues a token that authenticates subsequent requests', function (): void {
    $user = User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $token = $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ])->assertCreated()->json('token');

    startNewRequestLifecycle();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson(route('api.v1.user'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('accepts an email in any casing', function (): void {
    User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $this->postJson(route('api.v1.login'), [
        'email' => '  TOUFIQ@Example.COM ',
        'password' => 'password-secret',
    ])->assertCreated();
});

it('falls back to the user agent when no device name is given', function (): void {
    $user = User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $this->withHeader('User-Agent', 'BlogApp/1.0 iOS')
        ->postJson(route('api.v1.login'), [
            'email' => 'toufiq@example.com',
            'password' => 'password-secret',
        ])->assertCreated();

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'BlogApp/1.0 iOS',
    ]);
});

it('rejects a wrong password without issuing a token', function (): void {
    $user = User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email')
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

    expect($user->tokens()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('gives the same error for an unknown email as for a wrong password', function (): void {
    User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $wrongPassword = $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'wrong-password',
    ]);

    $unknownEmail = $this->postJson(route('api.v1.login'), [
        'email' => 'nobody@example.com',
        'password' => 'wrong-password',
    ]);

    expect($unknownEmail->status())->toBe($wrongPassword->status())
        ->and($unknownEmail->json('errors.email'))->toBe($wrongPassword->json('errors.email'));
});

it('logs in an account whose email is not verified', function (): void {
    $user = User::factory()->unverified()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ])->assertCreated()
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('token_type', 'Bearer');

    expect($user->tokens()->count())->toBe(1);
});

it('validates the required login fields', function (array $payload, array $errors): void {
    $this->postJson(route('api.v1.login'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'nothing supplied' => [[], ['email', 'password']],
    'missing password' => [['email' => 'toufiq@example.com'], ['password']],
    'missing email' => [['password' => 'password-secret'], ['email']],
    'malformed email' => [['email' => 'not-an-email', 'password' => 'password-secret'], ['email']],
]);

it('queues the login audit instead of writing it inline', function (): void {
    $user = User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ])->assertCreated();

    expect($user->refresh()->last_login_at)->toBeNull();

    Queue::assertPushed(
        RecordUserLogin::class,
        fn (RecordUserLogin $job): bool => $job->user->is($user) && $job->ipAddress !== '',
    );
});

it('records the login audit when the queued job runs', function (): void {
    $user = User::factory()->create();
    $updatedAt = $user->updated_at;

    $this->travel(5)->minutes();

    (new RecordUserLogin($user, '203.0.113.7'))->handle();

    $user->refresh();

    expect($user->last_login_at)->not->toBeNull()
        ->and($user->last_login_ip)->toBe('203.0.113.7')
        ->and($user->updated_at->timestamp)->toBe($updatedAt->timestamp);
});

it('locks out an email after five failed attempts and clears on success', function (): void {
    User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.v1.login'), [
            'email' => 'toufiq@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    Event::fake([Lockout::class]);

    $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ])->assertTooManyRequests()
        ->assertJsonPath('errors.email.0', 'Too many login attempts. Please try again in 60 seconds.');

    Event::assertDispatched(Lockout::class);

    $this->travel(61)->seconds();

    $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ])->assertCreated();
});

it('does not let failed attempts on one account lock out another', function (): void {
    User::factory()->create(['email' => 'toufiq@example.com', 'password' => 'password-secret']);
    User::factory()->create(['email' => 'other@example.com', 'password' => 'password-secret']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.v1.login'), [
            'email' => 'toufiq@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson(route('api.v1.login'), [
        'email' => 'other@example.com',
        'password' => 'password-secret',
    ])->assertCreated();
});

it('issues a distinct token per device', function (): void {
    $user = User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $first = $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
        'device_name' => 'iphone',
    ])->json('token');

    $second = $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
        'device_name' => 'macbook',
    ])->json('token');

    expect($first)->not->toBe($second)
        ->and($user->tokens()->count())->toBe(2);
});

it('honours the configured token expiration', function (): void {
    config()->set('sanctum.expiration', 60);

    User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $token = $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ])->json('token');

    startNewRequestLifecycle();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson(route('api.v1.user'))
        ->assertOk();

    $this->travel(61)->minutes();
    startNewRequestLifecycle();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson(route('api.v1.user'))
        ->assertUnauthorized();
});

it('never stores the plain text token', function (): void {
    User::factory()->create([
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ]);

    $token = $this->postJson(route('api.v1.login'), [
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
    ])->json('token');

    expect(PersonalAccessToken::query()->where('token', $token)->exists())->toBeFalse()
        ->and(PersonalAccessToken::findToken($token))->not->toBeNull();
});
