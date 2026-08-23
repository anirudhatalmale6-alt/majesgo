<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desde dónde está usando la app cada persona: la app instalada de Play o el navegador.
     *
     * Durante la prueba cerrada es el dato que hace falta para saber quién instaló de verdad
     * y quién sigue entrando por el enlace web. Sin esto solo se puede preguntar por WhatsApp
     * y creerle a la respuesta.
     */
    public function up(): void
    {
        foreach (['passengers', 'drivers'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('app_source', 10)->nullable()->after('last_active_at'); // play | web
                $t->timestamp('app_seen_at')->nullable()->after('app_source');
            });
        }
    }

    public function down(): void
    {
        foreach (['passengers', 'drivers'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['app_source', 'app_seen_at']);
            });
        }
    }
};
