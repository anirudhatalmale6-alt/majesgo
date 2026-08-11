<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('type');                            // perfil | vehiculo
            $table->string('path');
            $table->string('status')->default('pendiente');    // pendiente | aprobado | rechazado
            $table->string('reject_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'type', 'status']);
            $table->index('status');
        });

        // Las fotos de vehículo que ya existían fueron cargadas por la central antes de que
        // hubiera moderación: se dan por aprobadas para no dejar ciegos a los conductores
        // que ya estaban operando.
        $existing = DB::table('drivers')->whereNotNull('vehicle_photo')->get(['id', 'vehicle_photo']);
        foreach ($existing as $d) {
            DB::table('driver_photos')->insert([
                'driver_id'   => $d->id,
                'type'        => 'vehiculo',
                'path'        => $d->vehicle_photo,
                'status'      => 'aprobado',
                'reviewed_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_photos');
    }
};
