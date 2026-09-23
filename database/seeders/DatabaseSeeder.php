<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Siembra inicial de una instalación nueva.
 *
 * Ya no siembra roles de Spatie: el permiso lo deciden `users.role` y
 * `users.access`, como en Yii2, y esas tablas quedaron sin uso (ver
 * `docs/DIAGNOSTICO-BASE-DE-DATOS.md`).
 *
 * La cuenta de administrador **no se crea sola**. Antes venía con usuario y
 * contraseña escritos aquí y un «TODO: eliminar antes de producción»; en un
 * sistema que se instala en casa de otros eso es un superusuario con
 * contraseña pública esperando a que alguien corra `db:seed` en el servidor.
 *
 * Para crear la primera cuenta:
 *   DEV_ADMIN_USER=admin DEV_ADMIN_PASSWORD='...' php artisan db:seed
 *
 * y se niega a hacerlo con `APP_ENV=production`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->primeraCuenta();
    }

    private function primeraCuenta(): void
    {
        $usuario = trim((string) config('demo.admin.usuario'));
        $clave = (string) config('demo.admin.password');

        if ($usuario === '' || $clave === '') {
            $this->command?->info('Sin cuenta inicial: define DEV_ADMIN_USER y DEV_ADMIN_PASSWORD si la necesitas.');

            return;
        }

        if (app()->environment('production')) {
            $this->command?->warn('En producción la cuenta inicial se crea a mano, no con el seeder.');

            return;
        }

        // Rol 20 = super administrador del esquema heredado. Antes decía 1, que
        // no es ninguno de los tres roles válidos (9, 10, 20), así que la cuenta
        // «de administrador» ni siquiera entraba a facturación.
        // Rol, acceso y estado no son asignables en masa: van con `forceFill`.
        User::firstOrNew(['username' => $usuario])->forceFill([
            'name' => 'Administrador',
            'email' => $usuario.'@'.parse_url((string) config('app.url'), PHP_URL_HOST),
            'password' => Hash::make($clave),
            'status' => 1,
            'role' => User::ROLE_SUPER_ADMIN,
            'access' => User::ACCESS_INTERNAL,
        ])->save();

        $this->command?->info("Cuenta inicial `{$usuario}` lista.");
    }
}
