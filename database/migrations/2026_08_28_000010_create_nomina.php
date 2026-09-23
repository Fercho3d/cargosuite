<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina interna: qué se le paga a cada quien en cada periodo.
 *
 * ⚠️ **Calcula lo que se paga, no lo que se retiene.** No hay aquí IMSS, ni
 * INFONAVIT, ni tablas de ISR, ni timbrado del CFDI de nómina: eso está
 * regulado, cambia cada año y es un producto en sí mismo. Lo que este módulo
 * hace es reunir sueldos, bonos, viajes y descuentos en un periodo, sacar el
 * neto y **exportarlo** al sistema fiscal que la empresa ya usa.
 *
 * Decirlo así no es una limitación escondida: es lo que evita el malentendido
 * más caro de este tipo de proyecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleado', function (Blueprint $tabla) {
            $tabla->increments('empleado_id');
            $tabla->string('nombre', 120);
            $tabla->string('numero', 30)->nullable();
            $tabla->string('puesto', 60)->nullable();
            $tabla->string('departamento', 60)->nullable();

            $tabla->string('rfc', 20)->nullable();
            $tabla->string('curp', 20)->nullable();
            $tabla->string('nss', 20)->nullable();

            $tabla->date('ingreso')->nullable();
            $tabla->decimal('salario_diario', 12, 4)->nullable();

            // Para la dispersión bancaria; el sistema no la ejecuta, la exporta.
            $tabla->string('banco', 40)->nullable();
            $tabla->string('clabe', 20)->nullable();

            /**
             * Un operador que además está en nómina se enlaza aquí. Es lo que
             * permite que sus liquidaciones de viaje entren a su recibo sin
             * volver a capturarlas.
             */
            $tabla->unsignedInteger('operador_id')->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->string('notas', 255)->nullable();

            $tabla->index(['activo', 'nombre']);
            $tabla->index('operador_id');
        });

        Schema::create('nomina', function (Blueprint $tabla) {
            $tabla->increments('nomina_id');
            $tabla->string('numero', 20);
            $tabla->date('desde');
            $tabla->date('hasta');
            $tabla->string('periodicidad', 12)->default('quincenal'); // semanal | quincenal | mensual
            $tabla->string('estado', 10)->default('abierta');          // abierta | pagada
            $tabla->dateTime('pagada_en')->nullable();
            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->dateTime('created_at')->nullable();
            $tabla->string('notas', 255)->nullable();

            $tabla->index('estado');
            $tabla->index('desde');
        });

        Schema::create('nomina_renglon', function (Blueprint $tabla) {
            $tabla->increments('renglon_id');
            $tabla->unsignedInteger('nomina_id');
            $tabla->unsignedInteger('empleado_id');

            $tabla->string('concepto', 120);
            // El signo vive en el TIPO y el importe siempre en positivo, igual
            // que en las liquidaciones: con el signo en el importe, una captura
            // en negativo duplicaría la resta sin que nadie lo note.
            $tabla->string('tipo', 12)->default('percepcion');
            $tabla->decimal('importe', 16, 4)->default(0);

            // De qué liquidación de viajes salió, si vino de ahí.
            $tabla->unsignedInteger('liquidacion_id')->nullable();

            $tabla->index('nomina_id');
            $tabla->index(['nomina_id', 'empleado_id']);
            $tabla->index('liquidacion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_renglon');
        Schema::dropIfExists('nomina');
        Schema::dropIfExists('empleado');
    }
};
