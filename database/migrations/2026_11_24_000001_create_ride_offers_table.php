<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cada vez que un conductor toma un viaje y se lo ofrece al pasajero.
 *
 * rides.counter_offer sólo guarda la contraoferta del conductor que QUEDÓ: si el pasajero
 * rechaza, Dispatch::releaseOffer() la pone en 0 para el siguiente. Sin esta tabla no hay
 * forma de saber después cuánto pedían los conductores ni cuántos pasajeros lo rechazaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ride_id')->index();
            $t->foreignId('driver_id')->index();
            $t->decimal('base_price', 8, 2);            // lo que pagaba el pasajero sin contraoferta
            $t->decimal('counter_offer', 8, 2)->default(0);
            $t->decimal('total', 8, 2);
            $t->boolean('libre')->default(false);       // monto escrito por el conductor (no un botón cerrado)
            $t->string('outcome', 12)->default('pendiente'); // pendiente|aceptado|rechazado|vencido|cancelado
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_offers');
    }
};
