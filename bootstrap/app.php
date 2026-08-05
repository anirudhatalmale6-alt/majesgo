<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'passenger' => \App\Http\Middleware\EnsurePassenger::class,
            'driver'    => \App\Http\Middleware\EnsureDriver::class,
        ]);
        // El service worker re-suscribe el push sin token CSRF (no tiene acceso al meta);
        // el endpoint igual exige sesión autenticada, así que es seguro exceptuarlo.
        $middleware->validateCsrfTokens(except: [
            'app/api/push/subscribe',
            'conductor/api/push/subscribe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('app/api/*') || $request->is('conductor/api/*'),
        );
    })->create();
