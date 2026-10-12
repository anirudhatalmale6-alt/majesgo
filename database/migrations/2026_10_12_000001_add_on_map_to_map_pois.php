<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separar "se puede BUSCAR" de "se DIBUJA en el mapa".
 *
 * Hasta ahora todo punto activo salía como chinche en el mapa. Con las 704 calles del plano
 * catastral eso tapaba el pueblo entero de iconos. Pero las calles SÍ tienen que poder
 * buscarse: son justo lo que la gente escribe y lo que Google no tiene de El Pedregal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_pois', function (Blueprint $table) {
            $table->boolean('on_map')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('map_pois', function (Blueprint $table) {
            $table->dropColumn('on_map');
        });
    }
};
