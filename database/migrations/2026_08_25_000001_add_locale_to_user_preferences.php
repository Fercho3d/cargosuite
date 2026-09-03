<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idioma de la interfaz, junto al tema: los dos son preferencias de quien mira
 * la pantalla, no datos del sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
