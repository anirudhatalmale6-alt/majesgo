<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->decimal('amount', 8, 2);
            $table->string('method')->default('yape');       // yape | transferencia | admin
            $table->string('reference')->nullable();          // código de operación
            $table->string('receipt_path')->nullable();       // comprobante
            $table->string('status')->default('pendiente');   // pendiente | aprobado | rechazado
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recharges');
    }
};
