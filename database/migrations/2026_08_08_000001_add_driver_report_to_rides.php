<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            // Reporte del conductor cuando el pasajero cancela (auditoría/soporte)
            $table->string('driver_report')->nullable()->after('cancel_reason');
            $table->timestamp('driver_reported_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['driver_report', 'driver_reported_at']);
        });
    }
};
