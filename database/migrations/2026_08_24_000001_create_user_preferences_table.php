<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de interfaz por usuario (tema claro/oscuro, densidad, etc.).
 *
 * Tabla nueva y propia de Laravel: la tabla heredada `users` no se toca. No lleva
 * llave foránea a propósito — en producción las tablas del sistema anterior son
 * MyISAM y las restricciones no se aplicarían; la integridad la cuida el modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('usr_id')->unique();
            $table->string('theme', 10)->default('system');
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
