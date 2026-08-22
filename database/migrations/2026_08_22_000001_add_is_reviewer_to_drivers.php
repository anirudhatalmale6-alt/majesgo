<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuenta de conductor para el revisor de Google Play.
 *
 * El revisor entra a la app de conductor, se pone "conectado" y desde ese momento
 * sería un conductor más para el despacho: podría recibir la solicitud de un pasajero
 * REAL de Majes y aceptarla. Habría una persona esperando un auto que no existe.
 *
 * Por eso el conductor marcado is_reviewer queda FUERA de Dispatch::eligibleDrivers()
 * y, en su lugar, recibe una solicitud simulada (rides.is_demo = true) para que pueda
 * probar el flujo completo. Es el espejo de passengers.is_reviewer, que le da un
 * conductor de prueba al revisor cuando entra por la app de pasajero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->boolean('is_reviewer')->default(false)->after('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('is_reviewer');
        });
    }
};
