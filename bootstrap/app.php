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
            // La posición en segundo plano la manda el cliente HTTP NATIVO del celular, que
            // no tiene de dónde sacar el token CSRF (no hay página, no hay meta). Sin esta
            // excepción cada envío respondería 419 y el pasajero seguiría viendo al conductor
            // congelado, sin un solo error visible en la app. Lo cazó la prueba del endpoint.
            // No abre un agujero: backgroundLocation() exige el token propio del conductor y
            // además un viaje vivo.
            'conductor/api/location-bg',
        ]);
        // Invitados (no logueados) que entran a una URL del panel → a la pantalla de login
        // (evita el error "Route [login] not defined").
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('app/api/*') || $request->is('conductor/api/*'),
        );
    })->create();
