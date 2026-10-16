<?php

use App\Http\Middleware\EnsureActiveStaff;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Erzap → website endpoints at /web_service/... (stateless, no /api prefix).
        then: fn () => Route::middleware('api')->group(base_path('routes/erzap.php')),
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectGuestsTo('/admin/login');
        $middleware->redirectUsersTo('/admin');
        $middleware->alias(['active-staff' => EnsureActiveStaff::class]);
        // DOKU authenticates notifications with an HMAC-SHA256 signature instead of a CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/doku', 'webhooks/doku-qris', 'webhooks/doku-va', 'authorization/v1/access-token/b2b']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
