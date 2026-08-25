<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Guarda QUÉ VERSIÓN de la app instaló cada dispositivo.
 *
 * Hace falta porque el timbre propio de MajesGo va DENTRO del apk (res/raw), y un canal
 * de Android que apunta a un sonido que no existe en esa versión se queda MUDO. Durante
 * una actualización de Play conviven las dos versiones, así que el servidor tiene que
 * elegir el canal por dispositivo: los que ya actualizaron reciben el timbre propio, los
 * que todavía no, el sonido del sistema de siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table) {
            $table->unsignedInteger('app_build')->default(0)->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table) {
            $table->dropColumn('app_build');
        });
    }
};
