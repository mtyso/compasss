<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\EnsureTelegramAllowed;
use App\Http\Middleware\SetLocaleFromRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Outside the web group on purpose: no session row, cookie or CSRF check for
            // Telegram's calls or for serving the static SPA shell.
            Route::post('telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');
            require __DIR__.'/../routes/spa.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [SetLocaleFromRequest::class]);
        $middleware->alias(['allowed' => EnsureTelegramAllowed::class]);
        // API clients get a JSON 401; there is no server-rendered login page.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
