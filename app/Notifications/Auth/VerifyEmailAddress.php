<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

/**
 * Sends the signed verification link that targets the versioned API endpoint
 * rather than the framework's default `verification.verify` web route.
 */
class VerifyEmailAddress extends VerifyEmail
{
    /**
     * Build the mail representation of the notification.
     *
     * @param  User  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Verify your email address'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('Please confirm your email address to activate your account.'))
            ->action(__('Verify Email Address'), $this->verificationUrl($notifiable))
            ->line(__('This link will expire in :count minutes.', [
                'count' => config('auth.verification.expire', 60),
            ]))
            ->line(__('If you did not create an account, no further action is required.'));
    }

    /**
     * Get the verification URL for the given notifiable.
     *
     * @param  User  $notifiable
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'api.v1.verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }
}
