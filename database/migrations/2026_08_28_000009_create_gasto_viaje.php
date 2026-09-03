<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos de viaje: combustible, casetas y lo demás que se paga en carretera.
 *
 * Van en **una sola tabla con un tipo** y no en tres: lo que se necesita casi
 * siempre es el total del viaje —para saber qué dejó— y tenerlos separados
 * obligaría a unir tres consultas cada vez. Los campos propios del combustible
 * (litros, precio por litro, odómetro) van nulables y solo se llenan ahí.
 *
 * El odómetro no es adorno: de dos cargas consecutivas de la misma unidad sale
 * el rendimiento, que es el número con el que un transportista detecta un robo
 * de diésel o un motor que se está descomponiendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gasto_viaje', function (Blueprint $tabla) {
            $tabla->increments('gasto_id');

            // Nulo mientras no se asigne: un operador carga diésel entre viajes
            // y el gasto existe antes de saber a cuál cargarlo.
            $tabla->unsignedInteger('booking')->nullable();

            $tabla->string('tipo', 15)->default('combustible');   // combustible | caseta | otro
            $tabla->date('fecha');
            $tabla->unsignedInteger('unidad_id')->nullable();
            $tabla->unsignedInteger('operador_id')->nullable();
            $tabla->unsignedInteger('provider_id')->nullable();   // estación, concesionaria
            $tabla->string('descripcion', 120)->nullable();

            // Solo del combustible.
            $tabla->decimal('litros', 10, 2)->nullable();
            $tabla->decimal('precio_litro', 10, 4)->nullable();
            $tabla->unsignedInteger('odometro')->nullable();

            $tabla->decimal('importe', 16, 4)->default(0);
            $tabla->string('forma_pago', 20)->nullable();
            $tabla->string('folio', 40)->nullable();

            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->dateTime('created_at')->nullable();

            $tabla->index('booking');
            $tabla->index(['unidad_id', 'odometro']);
            $tabla->index(['tipo', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gasto_viaje');
    }
};
