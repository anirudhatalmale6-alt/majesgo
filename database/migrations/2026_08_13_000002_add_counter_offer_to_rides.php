<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONTRAOFERTA DEL CONDUCTOR (importes cerrados)
 *
 * El conductor puede pedir un poco más por una carrera concreta (lluvia, de noche, destino
 * sin retorno, pasajero que ofertó por debajo de lo sugerido). NO es una negociación abierta:
 * solo puede elegir entre los importes que la central define en Configuración (+3 y +5 por
 * defecto). Cualquier otro monto se ignora en el servidor.
 *
 *   rides.counter_offer  monto que el conductor añadió al aceptar (0 = aceptó tal cual)
 *
 * Total que paga el pasajero = offered_price (viaje A→B) + approach_fee + counter_offer.
 *
 * El pasajero SIEMPRE ve el desglose y el total final antes de confirmar, en la misma pantalla
 * de "Confirma tu conductor" que ya existía: si no le convence, toca "Buscar otro" y el viaje
 * vuelve a los demás conductores (a ese conductor ya no se le vuelve a ofrecer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->decimal('counter_offer', 8, 2)->default(0)->after('approach_fee');
        });

        $defaults = [
            'counter_offer_enabled' => '1',
            'counter_offer_options' => '3,5',
        ];

        foreach ($defaults as $k => $v) {
            if (Setting::where('key', $k)->doesntExist()) {
                Setting::put($k, $v);
            }
        }

        Setting::flushCache();
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('counter_offer');
        });
    }
};
