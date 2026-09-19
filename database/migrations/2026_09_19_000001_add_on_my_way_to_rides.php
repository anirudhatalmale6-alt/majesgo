<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Ya salgo": el pasajero avisa que está bajando.
     *
     * Se guarda la HORA y no un sí/no porque el dato útil es cuánto tardó en salir desde que
     * el conductor marcó su llegada: es el reclamo típico de los dos lados ("me hizo esperar"
     * contra "nunca llegó") y sin la hora no hay forma de resolverlo.
     *
     * También sirve de antirrebote: el pasajero puede tocar el botón varias veces y el
     * servidor decide, mirando esta marca, si le vuelve a sonar el aviso al conductor.
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->timestamp('on_my_way_at')->nullable()->after('arrived_at');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('on_my_way_at');
        });
    }
};
