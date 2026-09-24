<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreo GPS de la flota.
 *
 * Cada equipo GPS se da de alta con su identificador (el IMEI, o el que use la
 * app del celular) y se liga a una unidad. Las posiciones llegan por la API
 * (`routes/api.php`), casi siempre reenviadas por un servidor Traccar que
 * traduce el protocolo de cada fabricante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gps_dispositivo', function (Blueprint $tabla) {
            $tabla->increments('dispositivo_id');
            $tabla->unsignedInteger('unidad_id')->nullable();
            $tabla->string('identificador', 60)->unique();   // IMEI o uniqueId de Traccar
            // Marca y protocolo: una flota puede traer equipos de varias marcas,
            // y cada uno se configura hacia el puerto de su protocolo en Traccar.
            $tabla->string('protocolo', 20)->default('teltonika');
            $tabla->string('modelo', 60)->nullable();
            $tabla->boolean('activo')->default(true);

            // La última posición, a la mano para el mapa: sin esto habría que
            // buscar la más reciente entre miles de renglones en cada refresco.
            $tabla->decimal('ultima_lat', 10, 7)->nullable();
            $tabla->decimal('ultima_lng', 10, 7)->nullable();
            $tabla->decimal('ultima_velocidad', 6, 1)->nullable();   // km/h
            $tabla->unsignedSmallInteger('ultimo_rumbo')->nullable(); // grados
            $tabla->dateTime('ultima_senal')->nullable();

            $tabla->index('unidad_id');
        });

        Schema::create('gps_posicion', function (Blueprint $tabla) {
            $tabla->bigIncrements('posicion_id');
            $tabla->unsignedInteger('dispositivo_id');
            $tabla->unsignedInteger('unidad_id')->nullable();
            $tabla->decimal('lat', 10, 7);
            $tabla->decimal('lng', 10, 7);
            $tabla->decimal('velocidad', 6, 1)->nullable();   // km/h
            $tabla->unsignedSmallInteger('rumbo')->nullable();
            $tabla->dateTime('fecha');                        // la del equipo
            $tabla->dateTime('recibido_en');

            $tabla->index(['unidad_id', 'fecha']);
            $tabla->index(['dispositivo_id', 'fecha']);
            $tabla->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_posicion');
        Schema::dropIfExists('gps_dispositivo');
    }
};
