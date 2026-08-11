<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Tarifa por tiempo (S/ 1.00 por minuto, mínimo S/ 10.00) y comisión porcentual (5%).
 * Sustituye la tarifa mixta base+km+min y la comisión de monto fijo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $new = [
            'fare_per_min'    => '1.00',   // S/ por minuto de viaje
            'fare_min'        => '10.00',  // tarifa mínima (carreras de hasta 10 min)
            'commission_pct'  => '5',      // % de la tarifa que se lleva la app
            'commission_min'  => '0.00',   // piso opcional de comisión (0 = sin piso)
        ];

        foreach ($new as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }

        // Parámetros de la fórmula anterior que ya no se usan.
        Setting::whereIn('key', ['fare_base', 'fare_per_km', 'commission_value'])->delete();

        Setting::flushCache();
    }

    public function down(): void
    {
        $old = [
            'fare_base'        => '3.00',
            'fare_per_km'      => '1.20',
            'fare_min'         => '4.00',
            'fare_per_min'     => '0.30',
            'commission_value' => '0.50',
        ];

        foreach ($old as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }

        Setting::whereIn('key', ['commission_pct', 'commission_min'])->delete();

        Setting::flushCache();
    }
};
