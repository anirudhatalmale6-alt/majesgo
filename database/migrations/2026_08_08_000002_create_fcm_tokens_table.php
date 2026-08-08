<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20);      // 'driver' | 'passenger'
            $table->unsignedBigInteger('owner_id');
            $table->string('token', 512)->unique(); // token FCM del dispositivo
            $table->string('platform', 20)->default('android');
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_tokens');
    }
};
