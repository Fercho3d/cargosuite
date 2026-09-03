<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flota propia: operadores y unidades.
 *
 * El sistema nació para un agente de carga, que **subcontrata** el transporte:
 * por eso solo sabía de proveedores (`provider` tipo 2). Una empresa de camiones
 * mueve con gente y equipo suyos, y eso no tenía dónde vivir.
 *
 * Las dos tablas llevan **vigencias**, que es lo que de verdad se opera: una
 * licencia vencida o un seguro caducado detiene un viaje, y hoy eso se controla
 * en una hoja aparte que nadie mira hasta que hay una multa.
 *
 * Sin llaves foráneas, como el resto de migraciones propias: en producción
 * conviven tablas MyISAM que no las admiten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operador', function (Blueprint $tabla) {
            $tabla->increments('operador_id');
            $tabla->string('nombre', 120);
            $tabla->string('numero', 30)->nullable();          // número de empleado
            $tabla->string('rfc', 20)->nullable();
            $tabla->string('curp', 20)->nullable();
            $tabla->string('nss', 20)->nullable();             // para el recibo, no para calcular
            $tabla->string('telefono', 40)->nullable();

            // Licencia federal: sin ella no puede salir, y vence.
            $tabla->string('licencia', 40)->nullable();
            $tabla->string('licencia_tipo', 20)->nullable();
            $tabla->date('licencia_vence')->nullable();
            $tabla->date('examen_medico_vence')->nullable();

            $tabla->date('ingreso')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->string('notas', 255)->nullable();

            $tabla->index(['activo', 'nombre']);
            $tabla->index('licencia_vence');
        });

        Schema::create('unidad', function (Blueprint $tabla) {
            $tabla->increments('unidad_id');
            $tabla->string('numero', 30);                       // número económico
            $tabla->string('tipo', 20)->default('tractor');     // tractor | caja | dolly | otro
            $tabla->string('placas', 20)->nullable();
            $tabla->string('marca', 40)->nullable();
            $tabla->string('modelo', 40)->nullable();
            $tabla->string('anio', 4)->nullable();
            $tabla->string('serie', 40)->nullable();            // VIN
            $tabla->string('permiso_sct', 40)->nullable();

            // Lo que caduca y detiene una unidad.
            $tabla->date('seguro_vence')->nullable();
            $tabla->date('verificacion_vence')->nullable();
            $tabla->unsignedInteger('kilometraje')->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->string('notas', 255)->nullable();

            $tabla->index(['activo', 'numero']);
            $tabla->index('seguro_vence');
        });

        // El expediente ya existía; solo hay que decirle quién y con qué lo mueve.
        Schema::table('booking', function (Blueprint $tabla) {
            $tabla->unsignedInteger('operador_id')->nullable();
            $tabla->unsignedInteger('unidad_id')->nullable();
            $tabla->unsignedInteger('caja_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking', fn (Blueprint $t) => $t->dropColumn(['operador_id', 'unidad_id', 'caja_id']));
        Schema::dropIfExists('unidad');
        Schema::dropIfExists('operador');
    }
};
