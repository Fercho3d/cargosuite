<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liquidación de operadores: lo que se le paga a cada quien por sus viajes.
 *
 * ⚠️ **Esto NO es nómina fiscal.** No calcula IMSS, ni ISR, ni aguinaldo, ni
 * timbra un CFDI de nómina. Es lo operativo: lo que el operador ganó por sus
 * viajes, menos sus anticipos y sus descuentos, y qué queda por pagarle. La
 * parte fiscal se exporta al sistema de nómina de la empresa.
 *
 * Es la misma forma que ya tienen las solicitudes de pago a proveedores —una
 * cabecera y sus renglones—, porque el problema es el mismo: juntar varias
 * cosas que se deben y pagarlas de una vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cómo se le paga a cada operador: monto fijo por viaje o porcentaje
        // del flete. Es lo que permite PROPONER la liquidación en vez de que
        // alguien la capture renglón por renglón.
        Schema::table('operador', function (Blueprint $tabla) {
            $tabla->string('tarifa_tipo', 12)->nullable();      // fijo | porcentaje
            $tabla->decimal('tarifa_valor', 12, 4)->nullable();
        });

        Schema::create('liquidacion', function (Blueprint $tabla) {
            $tabla->increments('liquidacion_id');
            $tabla->string('numero', 20);
            $tabla->unsignedInteger('operador_id');
            $tabla->date('desde');
            $tabla->date('hasta');

            // abierta = todavía se le puede mover. pagada = ya salió el dinero.
            $tabla->string('estado', 10)->default('abierta');
            $tabla->dateTime('pagada_en')->nullable();
            $tabla->unsignedInteger('bank_id')->nullable();

            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->dateTime('created_at')->nullable();
            $tabla->string('notas', 255)->nullable();

            $tabla->index(['operador_id', 'estado']);
            $tabla->index('desde');
        });

        Schema::create('liquidacion_renglon', function (Blueprint $tabla) {
            $tabla->increments('renglon_id');
            $tabla->unsignedInteger('liquidacion_id');

            // De qué viaje sale. Nulo en lo que no cuelga de un viaje: un
            // préstamo, un bono de puntualidad, un descuento por daño.
            $tabla->unsignedInteger('booking')->nullable();

            $tabla->string('concepto', 120);

            // percepcion suma, deduccion resta. Se guarda el signo en el TIPO y
            // el importe siempre en positivo: si el signo viviera en el importe,
            // una captura con menos duplicaría la resta y nadie lo notaría.
            $tabla->string('tipo', 12)->default('percepcion');
            $tabla->decimal('importe', 16, 4)->default(0);
            $tabla->date('fecha')->nullable();

            $tabla->index('liquidacion_id');
            $tabla->index('booking');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidacion_renglon');
        Schema::dropIfExists('liquidacion');
        Schema::table('operador', fn (Blueprint $t) => $t->dropColumn(['tarifa_tipo', 'tarifa_valor']));
    }
};
