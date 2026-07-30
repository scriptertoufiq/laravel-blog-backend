<?php

use App\Jobs\Auth\SendEmailVerificationLink;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailAddress;
use Illuminate\Auth\Events\Verified;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

function verificationUrl(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('api.v1.verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => $hash ?? sha1($user->getEmailForVerification()),
    ]);
}

it('verifies the email address from a signed link', function (): void {
    Event::fake([Verified::class]);

    $user = User::factory()->unverified()->create();

    $this->getJson(verificationUrl($user))
        ->assertOk()
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('message', 'Your email address has been verified.');

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();

    Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));
});

it('reports an already verified address without re-firing the event', function (): void {
    Event::fake([Verified::class]);

    $user = User::factory()->create();

    $this->getJson(verificationUrl($user))
        ->assertOk()
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('message', 'Your email address was already verified.');

    Event::assertNotDispatched(Verified::class);
});

it('rejects a link with a tampered hash', function (): void {
    $user = User::factory()->unverified()->create();

    $this->getJson(verificationUrl($user, sha1('someone-else@example.com')))
        ->assertForbidden();

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects a link with an invalid signature', function (): void {
    $user = User::factory()->unverified()->create();

    $this->getJson(verificationUrl($user).'-tampered')->assertForbidden();

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects an expired link', function (): void {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('api.v1.verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->travel(61)->minutes();

    $this->getJson($url)->assertForbidden();

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('returns not found for an unknown user id', function (): void {
    $url = URL::temporarySignedRoute('api.v1.verification.verify', now()->addHour(), [
        'id' => 999,
        'hash' => sha1('ghost@example.com'),
    ]);

    $this->getJson($url)->assertNotFound();
});

it('queues a fresh link for an unverified address', function (): void {
    Queue::fake();

    $user = User::factory()->unverified()->create();

    $this->postJson(route('api.v1.verification.send'), ['email' => $user->email])
        ->assertAccepted()
        ->assertJsonStructure(['message']);

    Queue::assertPushed(
        SendEmailVerificationLink::class,
        fn (SendEmailVerificationLink $job): bool => $job->user->is($user),
    );
});

it('does not queue a link for an already verified address', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $this->postJson(route('api.v1.verification.send'), ['email' => $user->email])
        ->assertAccepted();

    Queue::assertNothingPushed();
});

it('gives the same response for an unknown address so accounts cannot be enumerated', function (): void {
    Queue::fake();

    $user = User::factory()->unverified()->create();

    $known = $this->postJson(route('api.v1.verification.send'), ['email' => $user->email]);
    $unknown = $this->postJson(route('api.v1.verification.send'), ['email' => 'nobody@example.com']);

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json('message'))->toBe($known->json('message'));

    Queue::assertPushed(SendEmailVerificationLink::class, 1);
});

it('validates the email when requesting a new link', function (): void {
    $this->postJson(route('api.v1.verification.send'), ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('sends a mail notification carrying a working verification link', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    (new SendEmailVerificationLink($user))->handle();

    Notification::assertSentTo($user, VerifyEmailAddress::class, function (VerifyEmailAddress $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        return str_contains($mail->actionUrl ?? '', '/api/v1/email/verify/'.$user->getKey());
    });
});

it('skips sending when the address was verified before the job ran', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    (new SendEmailVerificationLink($user))->handle();

    Notification::assertNothingSent();
});

it('renders a verification email that the recipient can actually follow', function (): void {
    $user = User::factory()->unverified()->create();

    (new SendEmailVerificationLink($user))->handle();

    $messages = app(MailManager::class)->mailer()->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1);

    $body = $messages[0]->getOriginalMessage()->getHtmlBody();

    preg_match('#https?://[^"\s]*/api/v1/email/verify/[^"\s]+#', (string) $body, $matches);

    expect($matches)->not->toBeEmpty();

    $this->getJson(html_entity_decode($matches[0]))->assertOk();

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('throttles repeated requests for a new link', function (): void {
    Queue::fake();

    $user = User::factory()->unverified()->create();

    foreach (range(1, 3) as $attempt) {
        $this->postJson(route('api.v1.verification.send'), ['email' => $user->email])
            ->assertAccepted();
    }

    $this->postJson(route('api.v1.verification.send'), ['email' => $user->email])
        ->assertTooManyRequests();
});

it('throttles per email address rather than lumping every address together', function (): void {
    Queue::fake();

    [$first, $second] = User::factory()->unverified()->count(2)->create();

    foreach (range(1, 3) as $attempt) {
        $this->postJson(route('api.v1.verification.send'), ['email' => $first->email])
            ->assertAccepted();
    }

    $this->postJson(route('api.v1.verification.send'), ['email' => $first->email])
        ->assertTooManyRequests();

    $this->postJson(route('api.v1.verification.send'), ['email' => $second->email])
        ->assertAccepted();
});

it('throttles a signed verification link independently of the resend endpoint', function (): void {
    $user = User::factory()->unverified()->create();
    $url = verificationUrl($user);

    foreach (range(1, 10) as $attempt) {
        $this->getJson($url)->assertOk();
    }

    $this->getJson($url)->assertTooManyRequests();
});
