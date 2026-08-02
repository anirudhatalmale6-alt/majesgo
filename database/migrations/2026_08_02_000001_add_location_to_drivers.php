<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('total_trips');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->boolean('is_demo')->default(false)->after('lng'); // conductor de simulación (hasta la app del conductor)
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng', 'is_demo']);
        });
    }
};
