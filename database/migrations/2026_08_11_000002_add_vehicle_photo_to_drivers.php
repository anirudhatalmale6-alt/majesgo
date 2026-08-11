<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // Foto del vehículo que ve el pasajero para reconocer el auto que lo recoge.
            $table->string('vehicle_photo')->nullable()->after('vehicle_year');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('vehicle_photo');
        });
    }
};
