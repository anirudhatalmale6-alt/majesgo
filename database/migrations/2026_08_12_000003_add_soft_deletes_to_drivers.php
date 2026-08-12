<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baja de conductores sin perder el historial.
     *
     * Borrar la fila de verdad arrastraba consigo sus recargas y sus movimientos de saldo
     * (van con cascade) y dejaba los viajes completados sin conductor. Es decir: se perdía
     * el registro de plata. Con deleted_at el conductor desaparece del panel y no puede
     * entrar a la app, pero todo lo que hizo queda registrado y se puede restaurar.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
