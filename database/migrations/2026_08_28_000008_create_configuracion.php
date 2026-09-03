<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de la instalación, editables desde el sistema.
 *
 * Hasta aquí, cosas como «esta empresa es terrestre» o «esta instalación factura
 * con CFDI» vivían solo en el `.env`, o sea que cambiarlas exigía entrar por SSH
 * al servidor. Para un sistema que se instala en casa de otros eso no sirve:
 * quien lo administra tiene que poder cambiarlo desde su pantalla.
 *
 * La tabla manda sobre el `.env`, que pasa a ser solo el valor de arranque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion', function (Blueprint $tabla) {
            $tabla->string('clave', 60)->primary();
            $tabla->text('valor')->nullable();
            $tabla->dateTime('modified_at')->nullable();
            $tabla->unsignedInteger('modified_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion');
    }
};
