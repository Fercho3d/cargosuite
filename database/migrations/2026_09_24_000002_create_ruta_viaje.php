<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ruta planeada de cada viaje, ya calculada por el servicio de rutas.
 *
 * Se calcula una vez y se guarda: el servicio cobra (o limita) por consulta y
 * la ruta no cambia mientras no cambien el origen, las paradas o el destino.
 * `firma` es el hash de esos puntos; si no coincide, se vuelve a calcular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ruta_viaje', function (Blueprint $tabla) {
            $tabla->unsignedInteger('booking_id')->primary();
            $tabla->string('firma', 32);
            $tabla->string('proveedor', 10);
            $tabla->decimal('distancia_km', 8, 1)->nullable();
            $tabla->unsignedInteger('duracion_min')->nullable();
            $tabla->longText('geometria');          // JSON: [[lat, lng], …]
            $tabla->dateTime('calculada_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruta_viaje');
    }
};
