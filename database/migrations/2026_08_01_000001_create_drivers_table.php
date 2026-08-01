<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();            // MG-0001
            $table->string('full_name');
            $table->string('dni')->nullable();           // documento de identidad
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->string('password');                  // login de la app del conductor
            $table->string('license_number')->nullable();

            // Vehículo
            $table->string('vehicle_make')->nullable();   // marca
            $table->string('vehicle_model')->nullable();  // modelo
            $table->string('vehicle_plate')->nullable();  // placa
            $table->string('vehicle_color')->nullable();
            $table->string('vehicle_year')->nullable();

            $table->string('photo_path')->nullable();

            // Estado operativo (lo controla la app del conductor)
            $table->string('status')->default('desconectado');   // disponible | ocupado | desconectado
            // Estado de la cuenta (lo controla el administrador)
            $table->string('account_status')->default('activo'); // activo | suspendido | bloqueado

            $table->decimal('saldo', 8, 2)->default(0);
            $table->decimal('rating', 3, 2)->default(5.00);
            $table->unsignedInteger('total_trips')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
