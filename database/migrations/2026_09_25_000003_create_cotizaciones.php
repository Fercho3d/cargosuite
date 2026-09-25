<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones de viaje: sobre una ruta configurada, con sus renglones de
 * precio, su vigencia y su estado. El viaje que sale de una cotización aceptada
 * la recuerda (`booking.cotizacion_id`) y se factura con lo cotizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizacion', function (Blueprint $tabla) {
            $tabla->increments('cotizacion_id');
            $tabla->string('numero', 20);
            // Cliente del catálogo, o un prospecto que todavía no lo es.
            $tabla->integer('client_id')->nullable();
            $tabla->string('prospecto', 150)->nullable();
            $tabla->string('correo', 150)->nullable();
            $tabla->unsignedInteger('ruta_id');
            $tabla->date('fecha_carga')->nullable();
            $tabla->date('vigencia');
            $tabla->string('tipo_unidad', 60)->nullable();
            // borrador | enviada | aceptada | rechazada (vencida se calcula con la vigencia)
            $tabla->string('estado', 12)->default('borrador');
            $tabla->text('condiciones')->nullable();
            $tabla->string('notas', 500)->nullable();
            $tabla->dateTime('enviada_en')->nullable();
            $tabla->dateTime('respondida_en')->nullable();
            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->dateTime('created_at')->nullable();
            $tabla->index(['estado', 'vigencia']);
        });

        Schema::create('cotizacion_renglon', function (Blueprint $tabla) {
            $tabla->increments('renglon_id');
            $tabla->unsignedInteger('cotizacion_id');
            $tabla->string('concepto', 100);
            $tabla->integer('charge_type_id')->nullable();
            $tabla->decimal('cantidad', 10, 2)->default(1);
            $tabla->decimal('precio', 14, 2);
            // De qué precio de la ruta salió, si salió de una.
            $tabla->unsignedInteger('tarifa_id')->nullable();
            $tabla->index('cotizacion_id');
        });

        Schema::table('booking', function (Blueprint $tabla) {
            $tabla->unsignedInteger('cotizacion_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking', fn (Blueprint $tabla) => $tabla->dropColumn('cotizacion_id'));
        Schema::dropIfExists('cotizacion_renglon');
        Schema::dropIfExists('cotizacion');
    }
};
