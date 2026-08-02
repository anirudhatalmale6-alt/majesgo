<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('password');                 // PIN/clave (hash)
            $table->decimal('rating', 3, 2)->default(5.00);
            $table->unsignedInteger('total_trips')->default(0);
            $table->unsignedInteger('cancel_count')->default(0);   // control de abuso
            $table->unsignedInteger('noshow_count')->default(0);
            $table->string('account_status')->default('activo');   // activo | suspendido | bloqueado
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passengers');
    }
};
