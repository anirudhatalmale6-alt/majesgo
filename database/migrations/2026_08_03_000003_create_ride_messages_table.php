<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chat dentro de la app entre pasajero y conductor durante un viaje (Hito 4).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->enum('sender', ['pasajero', 'conductor']);
            $table->string('body', 500);
            $table->timestamps();
            $table->index(['ride_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_messages');
    }
};
