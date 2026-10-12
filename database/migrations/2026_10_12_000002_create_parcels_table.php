<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcelas agrícolas de la irrigación Majes, sacadas del plano catastral de la municipalidad.
 *
 * La dirección rural de Majes es "sección + parcela" ("C2 Parcela 15"). La numeración vuelve
 * a empezar en 1 en cada sección (confirmado por el cliente), así que la clave es el PAR
 * (section, number), nunca el número solo: la parcela 15 existe en casi todas las secciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcels', function (Blueprint $table) {
            $table->id();
            $table->string('section', 4);            // "C2", "D5"
            $table->unsignedSmallInteger('number');  // 1..N dentro de la sección
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('source', 40)->default('plano_municipal');
            $table->timestamps();
            $table->unique(['section', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcels');
    }
};
