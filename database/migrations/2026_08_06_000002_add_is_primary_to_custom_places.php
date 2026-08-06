<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_places', function (Blueprint $table) {
            // Principal = nombre siempre visible (incluso de lejos). Secundaria = aparece al acercar.
            $table->boolean('is_primary')->default(false)->after('radius_m');
        });
    }

    public function down(): void
    {
        Schema::table('custom_places', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
