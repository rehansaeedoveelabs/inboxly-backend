<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('shopify:prune-oauth-states')->hourly();
        $schedule->command('contact-exports:prune')->daily();
        $schedule->command('campaigns:dispatch-due')->everyMinute();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth: prepends EnsureFrontendRequestsAreStateful to the api group.
        $middleware->statefulApi();

        // SPA has no named web `login` route. Returning null lets auth middleware throw
        // AuthenticationException; api/* still renders as JSON 401 via shouldRenderJsonWhen.
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
