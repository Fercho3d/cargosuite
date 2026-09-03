<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enseña qué credenciales viven fuera de `users`.
 *
 * Es lo que hay que mirar antes de correr la migración opcional que las quita:
 * dice cuántas cuentas quedarían fuera del portal viejo, que es la única razón
 * por la que esas columnas siguen existiendo.
 */
class ReportLegacyCredentials extends Command
{
    protected $signature = 'seguridad:credenciales-heredadas';

    protected $description = 'Lista las credenciales que viven fuera de la tabla de usuarios';

    /** Tabla => columnas de credencial. */
    private const COLUMNAS = [
        'client' => ['password', 'auth_key', 'password_reset_token', 'verification_code'],
        'provider' => ['password', 'auth_key', 'password_reset_token', 'verification_code'],
        'carrier' => ['password', 'auth_key', 'password_reset_token'],
    ];

    public function handle(): int
    {
        $filas = [];
        $total = 0;

        foreach (self::COLUMNAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'password')) {
                $filas[] = [$tabla, '—', 'ya no tiene columnas de credencial'];

                continue;
            }

            $con = DB::table($tabla)->whereNotNull('password')->where('password', '<>', '')->count();
            $total += $con;

            $presentes = array_filter($columnas, fn ($c) => Schema::hasColumn($tabla, $c));

            $filas[] = [$tabla, $con, implode(', ', $presentes)];
        }

        $this->table([__('Tabla'), __('Con contraseña'), __('Columnas')], $filas);

        if ($total === 0) {
            $this->info('No hay credenciales fuera de la tabla de usuarios.');

            return self::SUCCESS;
        }

        $this->warn("Hay {$total} credenciales fuera de `users`: no pasan por Fortify ni por el 2FA.");
        $this->line('Se quitan con:');
        $this->line('  php artisan migrate --path=database/migrations/opcionales');
        $this->line('⚠️  Solo cuando el portal antiguo ya no se use: es lo único que las lee.');

        return self::SUCCESS;
    }
}
