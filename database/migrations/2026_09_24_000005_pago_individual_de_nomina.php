<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago individual de la nómina: a cada empleado se le puede pagar (y timbrar)
 * por su cuenta, sin esperar a que se pague la nómina entera.
 *
 * El renglón de `nomina_recibo` pasa a ser el recibo del empleado aunque no
 * esté timbrado todavía (`estado = pendiente`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_recibo', function (Blueprint $tabla) {
            $tabla->dateTime('pagado_en')->nullable();
            // pendiente | timbrado | error | cancelado
            $tabla->string('estado', 12)->default('pendiente')->change();
        });
    }

    public function down(): void
    {
        Schema::table('nomina_recibo', fn (Blueprint $tabla) => $tabla->dropColumn('pagado_en'));
    }
};
