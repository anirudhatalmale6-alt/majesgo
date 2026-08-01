<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldo_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('type');                          // recarga | comision | ajuste
            $table->decimal('amount', 8, 2);                 // firmado: +recarga, -comision
            $table->decimal('balance_after', 8, 2);
            $table->string('description')->nullable();
            $table->string('ref_type')->nullable();          // recharge | ride | manual
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldo_movements');
    }
};
