<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rutas con su tarifa y su costo, y el precio del diésel por día.
 *
 * Nada se sobrescribe: un precio nuevo es un renglón nuevo con su «vigente
 * desde», y el anterior se queda como histórico. Así un viaje de hace tres
 * meses se sigue costeando con el diésel y la tarifa de su fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ruta', function (Blueprint $tabla) {
            $tabla->increments('ruta_id');
            // Los mismos catálogos que el viaje: origen (`loading_ports`) y destino (`dicharge_port`).
            $tabla->integer('origen_id');
            $tabla->integer('destino_id');
            $tabla->decimal('km', 8, 1);
            $tabla->decimal('horas', 5, 1)->nullable();
            // Kilómetros por litro del tractor cargado en esta ruta: la sierra no rinde como el plano.
            $tabla->decimal('rendimiento', 5, 2)->default(2.2);
            $tabla->boolean('activo')->default(true);
            $tabla->string('notas', 255)->nullable();
            $tabla->unique(['origen_id', 'destino_id']);
        });

        Schema::create('tarifa_ruta', function (Blueprint $tabla) {
            $tabla->increments('tarifa_id');
            $tabla->unsignedInteger('ruta_id');
            // venta (al cliente) | costo (de la ruta con unidad propia) | subcontrato (flete con externo)
            $tabla->string('tipo', 12);
            $tabla->string('concepto', 80);
            // Venta: sin cliente es el precio general; con cliente, su tarifa especial.
            $tabla->integer('client_id')->nullable();
            $tabla->integer('provider_id')->nullable();
            $tabla->decimal('precio', 14, 2);
            $tabla->date('vigente_desde');
            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->dateTime('created_at')->nullable();
            $tabla->index(['ruta_id', 'tipo', 'vigente_desde']);
        });

        Schema::create('precio_diesel', function (Blueprint $tabla) {
            $tabla->increments('precio_diesel_id');
            $tabla->date('fecha')->unique();
            $tabla->decimal('precio', 8, 2);
            $tabla->unsignedInteger('created_by')->nullable();
            $tabla->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precio_diesel');
        Schema::dropIfExists('tarifa_ruta');
        Schema::dropIfExists('ruta');
    }
};
