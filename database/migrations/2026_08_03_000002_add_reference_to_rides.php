<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Referencia/indicación del punto de recojo que escribe el pasajero
// (útil en zonas sin numeración formal: "casa portón azul, frente al parque").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->string('reference', 200)->nullable()->after('origin_address');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('reference');
        });
    }
};
