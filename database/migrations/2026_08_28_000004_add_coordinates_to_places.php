<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordenadas de los lugares, para poder dibujar el mapa de rutas.
 *
 * Los cuatro catálogos de lugar guardaban solo el nombre: sin latitud y longitud
 * no hay nada que pintar. Van nulables porque los lugares que ya existen no las
 * tienen y nadie va a capturar ciento setenta puertos de golpe — el mapa
 * simplemente ignora los que no las tengan.
 *
 * `decimal(9,6)` da precisión de ~11 cm, de sobra para un mapa del mundo, y
 * evita el redondeo de un `float`.
 */
return new class extends Migration
{
    private const TABLAS = ['loading_ports', 'dicharge_port', 'final_destination', 'pickup_place'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla) || Schema::hasColumn($tabla, 'latitud')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) {
                $t->decimal('latitud', 9, 6)->nullable();
                $t->decimal('longitud', 9, 6)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'latitud')) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn(['latitud', 'longitud']));
            }
        }
    }
};
