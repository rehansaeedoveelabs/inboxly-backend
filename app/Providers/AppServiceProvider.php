<?php

namespace App\Providers;

use App\Support\Messaging\BrevoEmailSender;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\Contracts\SmsSender;
use App\Support\Messaging\DisabledSmsSender;
use App\Support\Messaging\FakeEmailSender;
use App\Support\Messaging\FakeSmsSender;
use App\Support\Meta\Contracts\MetaProvider;
use App\Support\Meta\FakeMetaProvider;
use App\Support\Meta\GraphMetaProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmailSender::class, function () {
            $driver = (string) config('services.messaging.email_driver', 'fake');

            return match ($driver) {
                'brevo' => $this->app->make(BrevoEmailSender::class),
                default => $this->app->make(FakeEmailSender::class),
            };
        });

        $this->app->singleton(SmsSender::class, function () {
            $driver = (string) config('services.messaging.sms_driver', 'disabled');

            return match ($driver) {
                'fake' => $this->app->make(FakeSmsSender::class),
                default => $this->app->make(DisabledSmsSender::class),
            };
        });

        $this->app->singleton(MetaProvider::class, function () {
            $driver = (string) config('services.meta.driver', 'fake');

            return match ($driver) {
                'meta' => $this->app->make(GraphMetaProvider::class),
                default => $this->app->make(FakeMetaProvider::class),
            };
        });
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8));

        // Login / register: per email + IP so one user cannot lock out another IP.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        // Contact export create/download: per authenticated user (fallback IP).
        RateLimiter::for('contact-exports', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(10)->by('contact-exports|'.$key);
        });

        RateLimiter::for('campaign-send', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(5)->by('campaign-send|'.$key);
        });

        RateLimiter::for('campaign-test-send', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(10)->by('campaign-test-send|'.$key);
        });

        RateLimiter::for('brevo-webhooks', function (Request $request) {
            return Limit::perMinute(120)->by('brevo-webhooks|'.$request->ip());
        });

        RateLimiter::for('meta-oauth', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(10)->by('meta-oauth|'.$key);
        });
    }
}
