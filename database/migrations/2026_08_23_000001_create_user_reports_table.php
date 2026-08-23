<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denuncias entre usuarios (el pasajero denuncia al conductor y viceversa).
     *
     * Google Play pregunta en la calificación de contenido si la app permite denunciar
     * usuarios; para poder responder que sí, tiene que existir el botón y este registro.
     * No se borra nunca: es el respaldo de la central si alguien reclama una suspensión.
     */
    public function up(): void
    {
        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();

            // Viaje en el que ocurrió. Siempre hay uno: solo se denuncia desde el chat
            // o desde la pantalla de fin de viaje.
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();

            // Quién denuncia y a quién. Guardamos el tipo porque las dos apps escriben acá.
            $table->string('reporter_type', 10);   // passenger | driver
            $table->unsignedBigInteger('reporter_id');
            $table->string('reported_type', 10);   // passenger | driver
            $table->unsignedBigInteger('reported_id');

            $table->string('reason', 60);          // clave del motivo (lista cerrada)
            $table->text('details')->nullable();   // lo que escribió el usuario

            $table->string('status', 12)->default('pendiente'); // pendiente | revisado
            $table->text('admin_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Una sola denuncia por viaje y por denunciante: evita que el botón se pueda
            // pulsar diez veces y llenar la bandeja de la central con el mismo caso.
            $table->unique(['ride_id', 'reporter_type', 'reporter_id'], 'user_reports_una_por_viaje');

            // La central abre siempre por denunciado ("¿este conductor tiene antecedentes?")
            $table->index(['reported_type', 'reported_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reports');
    }
};
