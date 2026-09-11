<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos que envía el conductor, con su estado de revisión.
 *
 * Misma forma que driver_photos, que ya funciona: lo que se sube queda PENDIENTE y no
 * reemplaza a lo aprobado hasta que la central lo revise. Se agregan los dos datos que
 * una foto no necesitaba y un documento sí: el número (de licencia, de póliza) para poder
 * cotejarlo, y la fecha de vencimiento.
 *
 * ⚠ El archivo NO va al disco público: un DNI o unos antecedentes no pueden quedar en una
 * URL que abre cualquiera. Se guardan en el disco privado y se sirven por una ruta con
 * sesión de la central (ver DriverDocuments::disk()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->string('path');
            $table->string('number')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status')->default('pendiente');   // pendiente | aprobado | rechazado
            $table->string('reject_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // la consulta caliente es "¿qué tiene aprobado este conductor?"
            $table->index(['driver_id', 'document_type_id', 'status']);
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
    }
};
