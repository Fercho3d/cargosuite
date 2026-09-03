<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos propios del expediente.
 *
 * Apagar los que sobran ya se podía (`MARCA_EXPEDIENTE_OCULTOS`); esto es la
 * otra mitad: **añadir** los que faltan. Un taller no solo quiere quitar
 * «buque», quiere pedir «número de serie» y «horas de uso», y eso no se puede
 * resolver con una columna nueva por cliente.
 *
 * El mecanismo es el mismo que el sistema ya usaba para los documentos por
 * cliente (`fields_by_client`): un catálogo de campos y una fila por valor.
 *
 * Sin llaves foráneas, como el resto de migraciones propias: en producción hay
 * tablas MyISAM que no las admiten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campo_expediente', function (Blueprint $tabla) {
            $tabla->increments('campo_id');

            // Identificador interno; es la llave con la que se guarda el valor,
            // así que renombrar la etiqueta no pierde lo capturado.
            $tabla->string('clave', 40)->unique();
            $tabla->string('etiqueta', 60);

            // text | number | date | boolean | select
            $tabla->string('tipo', 10)->default('text');

            /** Opciones del desplegable, separadas por «|». Solo para `select`. */
            $tabla->string('opciones', 255)->nullable();

            /** En qué apartado del formulario sale. Vacío = uno propio al final. */
            $tabla->string('grupo', 40)->nullable();

            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->boolean('obligatorio')->default(false);
            $tabla->boolean('activo')->default(true);

            $tabla->index(['activo', 'orden']);
        });

        Schema::create('valor_por_expediente', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('booking');
            $tabla->unsignedInteger('campo_id');

            // Todo se guarda como texto a propósito: el tipo lo pone el campo y
            // puede cambiar, y una columna por tipo obligaría a migrar datos
            // cada vez que alguien corrige «número» por «texto».
            $tabla->string('valor', 255)->nullable();

            $tabla->unique(['booking', 'campo_id']);
            $tabla->index('campo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valor_por_expediente');
        Schema::dropIfExists('campo_expediente');
    }
};
