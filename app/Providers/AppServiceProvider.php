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

        /*
         * El paginador por defecto de Laravel está escrito con clases de Tailwind, que
         * este panel no usa: se veía «Showing 1 to 30 of 275 results» sin estilo y en
         * inglés en las siete pantallas que paginan. La vista propia usa el CSS que el
         * panel ya tenía (.pagi) y está en español.
         */
        \Illuminate\Pagination\Paginator::defaultView('vendor.pagination.majesgo');
        \Illuminate\Pagination\Paginator::defaultSimpleView('vendor.pagination.majesgo');
    }
}
