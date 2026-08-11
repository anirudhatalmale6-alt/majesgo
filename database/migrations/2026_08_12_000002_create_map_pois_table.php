<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Puntos de referencia que se dibujan en el mapa (grifos, mercados, hoteles…).
     *
     * Tabla aparte de custom_places a propósito: aquellas son ZONAS con radio que dan
     * nombre al origen y destino de un viaje (CustomPlace::zoneAt). Si mezcláramos aquí
     * los 273 comercios, un viaje pasaría a llamarse "Inkafarma" en vez de "El Pedregal".
     */
    public function up(): void
    {
        Schema::create('map_pois', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('category', 24);                  // grifo | mercado | hotel | banco…
            $table->unsignedTinyInteger('priority')->default(2); // 1 se ve de lejos, 3 solo muy cerca
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->boolean('active')->default(true);
            $table->string('source', 12)->default('osm');     // osm | manual
            $table->unsignedBigInteger('osm_id')->nullable(); // para no duplicar al reimportar
            $table->timestamps();

            $table->index(['active', 'priority']);
            $table->unique(['source', 'osm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_pois');
    }
};
