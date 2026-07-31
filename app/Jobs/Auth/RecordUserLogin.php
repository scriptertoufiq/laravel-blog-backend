<?php

namespace App\Jobs\Auth;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the login audit trail off the request thread so signing in never waits
 * on a database write that the response does not depend on.
 */
class RecordUserLogin implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30];

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 15;

    public function __construct(
        public User $user,
        public string $ipAddress,
        public ?DateTimeInterface $occurredAt = null,
    ) {
        $this->occurredAt ??= now();
    }

    /**
     * Execute the job.
     *
     * Writes through the base query builder so that `updated_at` is left alone
     * — signing in should not masquerade as a change to the profile itself.
     */
    public function handle(): void
    {
        User::query()
            ->whereKey($this->user->getKey())
            ->toBase()
            ->update([
                'last_login_at' => $this->occurredAt,
                'last_login_ip' => $this->ipAddress,
            ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Failed to record the user login.', [
            'user_id' => $this->user->getKey(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
