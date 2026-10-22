<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Iniciar el viaje lejos del recojo (pedido de la central, 22 oct).
 *
 * Un conductor recogió al pasajero, se olvidó de marcar "Llegué" y, ya a más del radio de
 * llegada, el servidor le rechazaba la llegada: el viaje quedaba trabado en "en camino".
 *
 *  · near_pickup_at     primera vez que el GPS del conductor lo ubicó dentro del radio del
 *                       recojo durante ESTE viaje. Es la prueba de que pasó por ahí.
 *  · onboard_request_at el conductor pidió iniciar desde lejos sin esa prueba: el pasajero
 *                       tiene que confirmar en su app que ya está en el taxi.
 *  · start_mode         cómo se inició: normal | cerca | gps | pasajero (para auditar).
 *  · start_far_m        a cuántos metros del recojo estaba al iniciar desde lejos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->timestamp('near_pickup_at')->nullable()->after('arrived_at');
            $table->timestamp('onboard_request_at')->nullable()->after('near_pickup_at');
            $table->string('start_mode', 12)->nullable()->after('started_at');
            $table->unsignedInteger('start_far_m')->nullable()->after('start_mode');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['near_pickup_at', 'onboard_request_at', 'start_mode', 'start_far_m']);
        });
    }
};
