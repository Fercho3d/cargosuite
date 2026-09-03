<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quita las credenciales que viven fuera de `users`.
 *
 * `client`, `provider` y `carrier` tienen cada uno `password`, `auth_key` y
 * `password_reset_token`: **tres sistemas de acceso en paralelo** al de verdad.
 * Ninguno pasa por Fortify ni por el 2FA, nadie los administra desde ninguna
 * pantalla, y el código de esta aplicación no los lee —solo los oculta—.
 *
 * ⚠️ **Va aparte de las migraciones normales a propósito.** La instalación
 * original convive con el portal Yii2, que SÍ autentica contra estas columnas:
 * correrla ahí deja fuera a quien todavía entre por el portal viejo. En una
 * instalación nueva no hay portal viejo y hay que correrla el primer día.
 *
 *   php artisan migrate --path=database/migrations/opcionales
 *
 * Antes conviene ver qué se va a llevar por delante:
 *
 *   php artisan seguridad:credenciales-heredadas
 */
return new class extends Migration
{
    /** Tabla => columnas de credencial que sobran. */
    private const COLUMNAS = [
        'client' => ['password', 'auth_key', 'password_reset_token', 'verification_code'],
        'provider' => ['password', 'auth_key', 'password_reset_token', 'verification_code'],
        'carrier' => ['password', 'auth_key', 'password_reset_token'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $presentes = array_values(array_filter(
                $columnas,
                fn (string $columna) => Schema::hasColumn($tabla, $columna),
            ));

            if ($presentes !== []) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn($presentes));
            }
        }
    }

    /**
     * Se pueden devolver las columnas, pero **no lo que había dentro**: eran
     * contraseñas y quedan borradas. Quien las necesite tiene que volver a
     * darlas de alta, que es justo lo que se quiere evitar.
     */
    public function down(): void
    {
        foreach (self::COLUMNAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) use ($tabla, $columnas) {
                foreach ($columnas as $columna) {
                    if (! Schema::hasColumn($tabla, $columna)) {
                        $t->string($columna, 255)->nullable();
                    }
                }
            });
        }
    }
};
