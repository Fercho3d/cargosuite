<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de demostración que llegan por la página pública.
 *
 * Se guardan en la base y no solo se mandan por correo a propósito: el correo
 * puede estar sin configurar o rebotar, y un prospecto perdido no se recupera.
 * La base es el registro; el correo, si lo hay, es el aviso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitud_demo', function (Blueprint $tabla) {
            $tabla->increments('solicitud_id');
            $tabla->string('nombre', 100);
            $tabla->string('empresa', 100)->nullable();
            $tabla->string('correo', 120);
            $tabla->string('telefono', 40)->nullable();
            $tabla->string('mensaje', 500)->nullable();

            // De dónde llegó, para saber qué canal funciona.
            $tabla->string('origen', 60)->nullable();
            $tabla->boolean('atendida')->default(false);
            $tabla->timestamp('created_at')->nullable();

            $tabla->index('created_at');
            $tabla->index('atendida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_demo');
    }
};
