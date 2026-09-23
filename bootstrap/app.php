<?php

use App\Http\Middleware\EnsureActiveStaff;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectGuestsTo('/admin/login');
        $middleware->redirectUsersTo('/admin');
        $middleware->alias(['active-staff' => EnsureActiveStaff::class]);
        // Midtrans authenticates notifications with a SHA-512 signature instead of a CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/midtrans']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
