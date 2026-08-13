<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COSTO DE APROXIMACIÓN (recojo)
 *
 * Hasta ahora la tarifa solo cubría el tramo A→B (recojo → destino). El trayecto que
 * el conductor recorre para LLEGAR al pasajero no se pagaba, así que una carrera de
 * S/ 10 con 15 km de acercamiento le costaba plata al conductor y simplemente la ignoraba.
 *
 * Se guarda en el viaje:
 *   approach_m   distancia real (por calles) del conductor al punto de recojo
 *   approach_fee monto cobrado por ese acercamiento
 *
 * Se calculan en el momento en que un conductor toma el viaje, con SU distancia real
 * (no la de un conductor hipotético): es el único número que hace rentable la carrera
 * para quien efectivamente la maneja. El pasajero lo ve y confirma antes de que el
 * conductor arranque, en la pantalla de confirmación que ya existía.
 *
 * Total que paga el pasajero = offered_price (viaje A→B) + approach_fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedInteger('approach_m')->nullable()->after('duration_s');
            $table->decimal('approach_fee', 8, 2)->default(0)->after('approach_m');
        });

        $defaults = [
            'approach_enabled' => '1',      // cobrar acercamiento sí/no
            'approach_free_km' => '3',      // radio gratuito: hasta aquí no se cobra nada
            'approach_per_km'  => '1.00',   // por cada km que pase del radio gratuito
            'approach_max'     => '15.00',  // tope, para que un caso raro no dispare el precio
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
            $table->dropColumn(['approach_m', 'approach_fee']);
        });

        Setting::whereIn('key', ['approach_enabled', 'approach_free_km', 'approach_per_km', 'approach_max'])->delete();
        Setting::flushCache();
    }
};
