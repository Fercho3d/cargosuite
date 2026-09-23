<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taller: mantenimiento de las unidades y almacén de refacciones.
 *
 * Es lo que separa a una empresa de camiones que lleva su flota en el sistema de
 * una que la lleva en un cuaderno. Dos piezas que se necesitan mutuamente:
 *
 * · **Órdenes de mantenimiento** (`mantenimiento`): qué se le hizo a qué unidad,
 *   preventivo o correctivo, con qué kilometraje, en el taller propio o en uno
 *   externo, cuánto costó la mano de obra y qué refacciones se le pusieron.
 * · **Almacén** (`refaccion` + `movimiento_refaccion`): qué hay, dónde está,
 *   cuánto costó y a qué se fue. Poner una refacción en una orden **la descuenta
 *   del almacén**: es lo que evita el inventario que nunca cuadra.
 *
 * ⚠️ `refaccion.existencia` está desnormalizada a propósito —el listado del
 * almacén no puede sumar el kárdex entero en cada renglón—, pero **la verdad es
 * el movimiento**: todo cambio de existencia escribe su fila, y una prueba
 * comprueba que la columna y la suma del kárdex no se separen.
 *
 * Sin llaves foráneas, como el resto: en producción varias tablas son MyISAM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refaccion', function (Blueprint $tabla) {
            $tabla->increments('refaccion_id');
            $tabla->string('codigo', 40);
            $tabla->string('nombre', 120);
            $tabla->string('categoria', 40)->nullable();   // llantas, filtros, frenos…
            $tabla->string('medida', 20)->nullable();      // pieza, litro, juego
            $tabla->string('ubicacion', 40)->nullable();   // rack o estante

            // Existencia y punto de reorden: lo que hace útil el almacén no es
            // saber qué hay, es saber qué está por acabarse.
            $tabla->decimal('existencia', 12, 2)->default(0);
            $tabla->decimal('minimo', 12, 2)->default(0);

            // Último costo de compra, para valuar la salida y la orden.
            $tabla->decimal('costo', 12, 4)->default(0);

            $tabla->boolean('activo')->default(true);
            $tabla->string('notas', 255)->nullable();

            $tabla->unique('codigo');
            $tabla->index(['activo', 'nombre']);
        });

        Schema::create('mantenimiento', function (Blueprint $tabla) {
            $tabla->increments('mantenimiento_id');
            $tabla->string('folio', 20);
            $tabla->unsignedInteger('unidad_id');

            $tabla->string('tipo', 12)->default('preventivo');  // preventivo | correctivo
            $tabla->string('estado', 10)->default('abierto');   // abierto | cerrado

            $tabla->date('entrada');
            $tabla->date('salida')->nullable();

            // El kilometraje al que se hizo: de aquí sale el próximo servicio.
            $tabla->unsignedInteger('odometro')->nullable();

            // En el taller propio o en uno externo, y de quién es la factura.
            $tabla->string('taller', 10)->default('interno'); // interno | externo
            $tabla->unsignedInteger('provider_id')->nullable();

            $tabla->string('descripcion', 200);
            $tabla->decimal('mano_obra', 14, 2)->default(0);
            $tabla->string('notas', 255)->nullable();

            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->dateTime('created_at')->nullable();

            $tabla->index(['unidad_id', 'entrada']);
            $tabla->index('estado');
        });

        Schema::create('mantenimiento_refaccion', function (Blueprint $tabla) {
            $tabla->increments('renglon_id');
            $tabla->unsignedInteger('mantenimiento_id');
            $tabla->unsignedInteger('refaccion_id');
            $tabla->decimal('cantidad', 12, 2)->default(1);

            // El costo se copia al ponerla: el del almacén cambia con la
            // siguiente compra, y una orden de hace un año no puede recalcularse
            // sola con el precio de hoy.
            $tabla->decimal('costo', 12, 4)->default(0);

            $tabla->index('mantenimiento_id');
            $tabla->index('refaccion_id');
        });

        Schema::create('movimiento_refaccion', function (Blueprint $tabla) {
            $tabla->increments('movimiento_id');
            $tabla->unsignedInteger('refaccion_id');

            $tabla->string('tipo', 10);  // entrada | salida | ajuste
            $tabla->decimal('cantidad', 12, 2);
            $tabla->decimal('costo', 12, 4)->default(0);
            $tabla->date('fecha');

            // De dónde vino o a dónde se fue.
            $tabla->unsignedInteger('mantenimiento_id')->nullable();
            $tabla->unsignedInteger('provider_id')->nullable();
            $tabla->string('folio', 40)->nullable();
            $tabla->string('notas', 255)->nullable();

            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->dateTime('created_at')->nullable();

            $tabla->index(['refaccion_id', 'fecha']);
            $tabla->index('mantenimiento_id');
        });

        Schema::table('unidad', function (Blueprint $tabla) {
            // El servicio preventivo: cada cuántos kilómetros toca y cuándo fue
            // el último. Con esto la pantalla avisa antes de que se rompa, que es
            // para lo que sirve el preventivo.
            $tabla->unsignedInteger('servicio_cada_km')->nullable()->after('kilometraje');
            $tabla->unsignedInteger('ultimo_servicio_km')->nullable()->after('servicio_cada_km');
            $tabla->date('ultimo_servicio')->nullable()->after('ultimo_servicio_km');
        });
    }

    public function down(): void
    {
        Schema::table('unidad', function (Blueprint $tabla) {
            $tabla->dropColumn(['servicio_cada_km', 'ultimo_servicio_km', 'ultimo_servicio']);
        });

        Schema::dropIfExists('movimiento_refaccion');
        Schema::dropIfExists('mantenimiento_refaccion');
        Schema::dropIfExists('mantenimiento');
        Schema::dropIfExists('refaccion');
    }
};
