<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Flujo de confirmación del conductor por el pasajero (Hito 4):
// el conductor "ofrece" el viaje y el pasajero lo confirma o busca otro.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->timestamp('offered_at')->nullable()->after('accepted_at');
            $table->json('excluded_driver_ids')->nullable()->after('driver_id');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['offered_at', 'excluded_driver_ids']);
        });
    }
};
