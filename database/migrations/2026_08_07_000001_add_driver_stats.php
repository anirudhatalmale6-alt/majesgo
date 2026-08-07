<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sesiones de conexión del conductor → "horas conectado"
        Schema::create('driver_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['driver_id', 'started_at']);
        });

        // Contadores para la "tasa de aceptación"
        Schema::table('drivers', function (Blueprint $table) {
            $table->unsignedInteger('stat_accepted')->default(0)->after('total_trips');
            $table->unsignedInteger('stat_rejected')->default(0)->after('stat_accepted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_sessions');
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['stat_accepted', 'stat_rejected']);
        });
    }
};
