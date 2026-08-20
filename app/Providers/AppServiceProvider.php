<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        /*
         * Las fechas relativas del panel ("hace 2 días") salían en inglés: Carbon usa el
         * locale de la app, que es 'en'. Se cambia solo el locale de Carbon y no el de
         * Laravel a propósito: tocar APP_LOCALE también cambiaría de dónde se leen los
         * mensajes de validación, y no hay traducciones en español en el proyecto.
         */
        \Carbon\Carbon::setLocale('es');
    }
}
