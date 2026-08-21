<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuenta de revisión para las tiendas (Google Play / App Store).
 *
 * El revisor prueba la app desde otro país y a cualquier hora, cuando no hay ningún
 * conductor real conectado en Majes. Sin esto pide un viaje, no aparece nadie, y la
 * reseña se cierra como "no pudimos revisar la funcionalidad de la app" → rechazo.
 *
 * Con esta bandera el conductor de prueba se activa SOLO para esa cuenta, sin tocar
 * demo_enabled, que sigue en 0 para que ningún pasajero real vea un conductor falso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->boolean('is_reviewer')->default(false)->after('account_status');
        });
    }

    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropColumn('is_reviewer');
        });
    }
};
