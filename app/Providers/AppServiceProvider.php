<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure the rate limiters used by the API routes.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by('ip:'.$request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by('ip:'.$request->ip()));

        RateLimiter::for('password-update', fn (Request $request) => Limit::perMinute(5)
            ->by('user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Every call spends real money at a third party, so this is metered per
        // author rather than per IP — a shared office must not exhaust one
        // person's allowance, and one account must not hide behind many IPs.
        RateLimiter::for('post-generation', fn (Request $request) => Limit::perMinute(5)
            ->by('user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('verification-link', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        RateLimiter::for('verification-request', fn (Request $request) => [
            Limit::perMinute(6)->by('ip:'.$request->ip()),
            Limit::perMinute(3)->by('email:'.$request->string('email')->trim()->lower()),
        ]);
    }
}
