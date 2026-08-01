<?php

namespace App\Services\Auth;

use App\Jobs\Auth\RecordUserLogin;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    /**
     * The number of failed attempts allowed per email and IP pair each minute.
     */
    private const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Exchange credentials for a personal access token.
     *
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function login(array $credentials, string $deviceName, string $ipAddress): array
    {
        $throttleKey = $this->throttleKey($credentials['email'], $ipAddress);

        $this->ensureIsNotRateLimited($throttleKey);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken($deviceName)->plainTextToken;

        RecordUserLogin::dispatch($user, $ipAddress);

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Revoke the token that authenticated the current request.
     */
    public function logout(Request $request): void
    {
        $request->user()?->currentAccessToken()?->delete();
    }

    /**
     * Revoke every token belonging to the given user.
     */
    public function logoutFromAllDevices(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Abort when too many failed attempts have been made for this email and IP.
     *
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(string $throttleKey): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, self::MAX_FAILED_ATTEMPTS)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ])->status(429);
    }

    /**
     * Build the failed-attempt throttle key for an email and IP pair.
     */
    private function throttleKey(string $email, string $ipAddress): string
    {
        return 'login:'.Str::transliterate(Str::lower($email)).'|'.$ipAddress;
    }
}
