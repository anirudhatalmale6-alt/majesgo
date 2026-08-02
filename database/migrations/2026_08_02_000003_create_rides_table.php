<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rides', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                        // MG-R-0001
            $table->foreignId('passenger_id')->constrained('passengers')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();

            // Origen y destino
            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->string('origin_address')->nullable();
            $table->decimal('dest_lat', 10, 7);
            $table->decimal('dest_lng', 10, 7);
            $table->string('dest_address')->nullable();

            // Ruta / precio
            $table->unsignedInteger('distance_m')->default(0);       // metros
            $table->unsignedInteger('duration_s')->default(0);       // segundos
            $table->decimal('suggested_price', 8, 2)->default(0);    // sugerido por el sistema
            $table->decimal('offered_price', 8, 2)->default(0);      // el que propone el pasajero
            $table->decimal('final_price', 8, 2)->nullable();        // cobrado al finalizar
            $table->string('payment_method')->default('efectivo');   // efectivo | yape
            $table->decimal('commission', 8, 2)->default(0);         // comisión descontada al conductor

            // Geometría de la ruta (polyline OSRM) para tracking en vivo
            $table->longText('route_to_pickup')->nullable();         // json [[lat,lng],...] conductor->recojo
            $table->longText('route_trip')->nullable();              // json [[lat,lng],...] recojo->destino

            // Máquina de estados
            $table->string('status')->default('solicitando');
            // solicitando | aceptado | en_camino | llego | a_bordo | completado | cancelado | sin_conductor
            $table->string('cancelled_by')->nullable();              // pasajero | conductor | sistema
            $table->string('cancel_reason')->nullable();

            // Calificaciones mutuas
            $table->unsignedTinyInteger('rating_to_driver')->nullable();
            $table->unsignedTinyInteger('rating_to_passenger')->nullable();

            // Marcas de tiempo del ciclo de vida
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->boolean('is_demo')->default(false);              // viaje con conductor simulado
            $table->timestamps();

            $table->index(['passenger_id', 'status']);
            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rides');
    }
};
