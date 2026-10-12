<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas parcelas tiene cada sección, según la central (no según el plano).
 *
 * El plano tiene números sin escribir: B1 tiene 109 parcelas pero su último rótulo es la 108.
 * Si "hasta dónde llega la sección" se sacara del plano, a quien vive en la 109 la app le
 * diría "revisa el número". Este total es además la prueba con la que se valida cada sección
 * antes de cargar sus parcelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_sections', function (Blueprint $table) {
            $table->string('section', 4)->primary();
            $table->unsignedSmallInteger('total');
            $table->string('source', 60)->default('central Majes Drive');
            $table->timestamps();
        });

        // Totales confirmados por la central el 12 oct 2026.
        $now = now();
        foreach (['C1' => 99, 'C2' => 114, 'E4' => 84, 'E5' => 81, 'B1' => 109,
                  'B3' => 104, 'E3' => 99, 'D1' => 138] as $s => $t) {
            DB::table('parcel_sections')->insert([
                'section' => $s, 'total' => $t, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_sections');
    }
};
