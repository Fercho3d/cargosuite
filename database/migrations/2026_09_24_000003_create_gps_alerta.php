<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alertas del rastreo: por ahora, una unidad que se sale de la ruta de su viaje.
 *
 * Una alerta se abre cuando la unidad se aleja de la ruta y se cierra sola
 * (`fin`) cuando vuelve; mientras está abierta sale en la campana y en el mapa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gps_alerta', function (Blueprint $tabla) {
            $tabla->increments('alerta_id');
            $tabla->unsignedInteger('unidad_id');
            $tabla->unsignedInteger('booking_id');
            $tabla->string('tipo', 20)->default('fuera_de_ruta');
            $tabla->dateTime('inicio');
            $tabla->dateTime('fin')->nullable();
            $tabla->decimal('lat', 10, 7);
            $tabla->decimal('lng', 10, 7);
            $tabla->decimal('distancia_km', 8, 1);   // la mayor a la que llegó

            $tabla->index(['unidad_id', 'fin']);
            $tabla->index('fin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_alerta');
    }
};
