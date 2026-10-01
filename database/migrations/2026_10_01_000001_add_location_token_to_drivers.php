<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credencial propia para mandar la ubicación desde el servicio en segundo plano.
     *
     * ¿Por qué no reusar la sesión, como hace el resto de la app? Porque en segundo plano el
     * envío ya no sale del WebView: lo hace el cliente HTTP nativo de Android, que tiene su
     * propio manejo de cookies. Si la cookie de sesión no viajara, el servidor respondería
     * "no autenticado" y la ubicación se perdería EN SILENCIO, que es el peor resultado
     * posible: el pasajero vería al conductor congelado y en los registros no habría ni un
     * error. Con un token propio en el cuerpo del pedido, la cookie deja de importar.
     *
     * Es de un solo conductor y sólo sirve para decir "estoy acá": no da acceso a nada más.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('location_token', 64)->nullable()->unique()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('location_token');
        });
    }
};
