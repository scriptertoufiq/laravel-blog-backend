<?php

namespace App\Jobs\Auth;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailAddress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendEmailVerificationLink implements ShouldQueue
{
    use Queueable;

    /**
     * Discard the job when the model it carries has been deleted.
     *
     * Queued jobs restore their models by id, which throws before `handle()`
     * ever runs — so a user or post deleted while a job was still queued would
     * otherwise land in `failed_jobs` for no useful reason.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    /**
     * The number of seconds the job can run before timing out.
     *
     * Kept well below the queue connection's `retry_after` so the job is never
     * released back onto the queue while it is still running.
     */
    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public User $user)
    {
        $this->onQueue('mail');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->user->hasVerifiedEmail()) {
            return;
        }

        $this->user->notify(new VerifyEmailAddress);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Failed to send the email verification link.', [
            'user_id' => $this->user->getKey(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
