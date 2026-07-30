<?php

use App\Jobs\Auth\SendEmailVerificationLink;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

it('registers a new unverified user', function (): void {
    $response = $this->postJson(route('api.v1.register'), [
        'name' => 'Toufiq Hasan',
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
        'password_confirmation' => 'password-secret',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Toufiq Hasan')
        ->assertJsonPath('data.email', 'toufiq@example.com')
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.email_verified_at', null)
        ->assertJsonStructure(['message', 'data' => ['id', 'name', 'email', 'email_verified', 'created_at']]);

    $user = User::query()->where('email', 'toufiq@example.com')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and(Hash::check('password-secret', $user->password))->toBeTrue();
});

it('never exposes credentials in the response', function (): void {
    $this->postJson(route('api.v1.register'), [
        'name' => 'Toufiq Hasan',
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
        'password_confirmation' => 'password-secret',
    ])->assertCreated()
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');
});

it('queues the verification link instead of sending it inline', function (): void {
    $this->postJson(route('api.v1.register'), [
        'name' => 'Toufiq Hasan',
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
        'password_confirmation' => 'password-secret',
    ])->assertCreated();

    Queue::assertPushed(
        SendEmailVerificationLink::class,
        fn (SendEmailVerificationLink $job): bool => $job->user->email === 'toufiq@example.com'
            && $job->queue === 'mail',
    );
});

it('normalises the name and email before persisting', function (): void {
    $this->postJson(route('api.v1.register'), [
        'name' => "  Toufiq   Hasan\n",
        'email' => '  TOUFIQ@Example.COM ',
        'password' => 'password-secret',
        'password_confirmation' => 'password-secret',
    ])->assertCreated();

    $this->assertDatabaseHas('users', [
        'name' => 'Toufiq Hasan',
        'email' => 'toufiq@example.com',
    ]);
});

it('rejects an email that is already registered', function (): void {
    User::factory()->create(['email' => 'toufiq@example.com']);

    $this->postJson(route('api.v1.register'), [
        'name' => 'Toufiq Hasan',
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
        'password_confirmation' => 'password-secret',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    Queue::assertNothingPushed();
});

it('rejects an email that differs only by casing from an existing account', function (): void {
    User::factory()->create(['email' => 'toufiq@example.com']);

    $this->postJson(route('api.v1.register'), [
        'name' => 'Toufiq Hasan',
        'email' => 'TOUFIQ@EXAMPLE.COM',
        'password' => 'password-secret',
        'password_confirmation' => 'password-secret',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('requires a confirmed password', function (): void {
    $this->postJson(route('api.v1.register'), [
        'name' => 'Toufiq Hasan',
        'email' => 'toufiq@example.com',
        'password' => 'password-secret',
        'password_confirmation' => 'something-else',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->assertDatabaseEmpty('users');
});

it('validates the required registration fields', function (array $payload, array $errors): void {
    $this->postJson(route('api.v1.register'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'nothing supplied' => [[], ['name', 'email', 'password']],
    'blank name' => [['name' => '', 'email' => 'a@example.com', 'password' => 'password-secret'], ['name']],
    'name too short' => [['name' => 'T', 'email' => 'a@example.com', 'password' => 'password-secret'], ['name']],
    'malformed email' => [['name' => 'Toufiq', 'email' => 'not-an-email', 'password' => 'password-secret'], ['email']],
    'missing password' => [['name' => 'Toufiq', 'email' => 'a@example.com'], ['password']],
    'password below the minimum length' => [
        ['name' => 'Toufiq', 'email' => 'a@example.com', 'password' => 'short', 'password_confirmation' => 'short'],
        ['password'],
    ],
]);

it('throttles repeated registration attempts from the same address', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.v1.register'), [
            'name' => 'Toufiq Hasan',
            'email' => "toufiq{$attempt}@example.com",
            'password' => 'password-secret',
            'password_confirmation' => 'password-secret',
        ])->assertCreated();
    }

    $this->postJson(route('api.v1.register'), [
        'name' => 'Toufiq Hasan',
        'email' => 'toufiq6@example.com',
        'password' => 'password-secret',
        'password_confirmation' => 'password-secret',
    ])->assertTooManyRequests();
});
