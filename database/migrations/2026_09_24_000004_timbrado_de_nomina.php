<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timbrado del CFDI de nómina (complemento Nómina 1.2).
 *
 * Lo que le faltaba a la base para poder timbrar un recibo: el domicilio fiscal
 * del empleado, el registro patronal de la compañía, con qué compañía se timbra
 * cada nómina y un renglón por recibo timbrado con su folio fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleado', function (Blueprint $tabla) {
            // El CFDI 4.0 exige el C.P. de su constancia fiscal y el estado donde trabaja.
            $tabla->string('codigo_postal', 5)->nullable();
            $tabla->string('entidad', 3)->nullable();
        });

        Schema::table('company', function (Blueprint $tabla) {
            // Solo para sueldos y salarios: asimilados no llevan registro patronal.
            $tabla->string('registro_patronal', 20)->nullable();
            // Clase de riesgo del IMSS (1 a 5).
            $tabla->string('riesgo_puesto', 1)->nullable();
        });

        Schema::table('nomina', function (Blueprint $tabla) {
            $tabla->integer('company_id')->nullable();
        });

        Schema::create('nomina_recibo', function (Blueprint $tabla) {
            $tabla->increments('recibo_id');
            $tabla->unsignedInteger('nomina_id');
            $tabla->unsignedInteger('empleado_id');
            $tabla->string('uuid', 40)->nullable();
            $tabla->string('rfc_emisor', 15)->nullable();
            // timbrado | error | cancelado
            $tabla->string('estado', 12);
            $tabla->string('mensaje', 500)->nullable();
            $tabla->dateTime('timbrado_en')->nullable();
            $tabla->dateTime('cancelado_en')->nullable();
            $tabla->unique(['nomina_id', 'empleado_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_recibo');
        Schema::table('nomina', fn (Blueprint $tabla) => $tabla->dropColumn('company_id'));
        Schema::table('company', fn (Blueprint $tabla) => $tabla->dropColumn(['registro_patronal', 'riesgo_puesto']));
        Schema::table('empleado', fn (Blueprint $tabla) => $tabla->dropColumn(['codigo_postal', 'entidad']));
    }
};
