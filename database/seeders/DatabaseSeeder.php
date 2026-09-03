<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Siembra inicial de una instalación nueva: solo los roles del sistema.
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
        foreach ([
            'super-admin',   // acceso total
            'admin',         // administración interna
            'operaciones',   // expedientes y unidades
            'facturacion',   // facturación y timbrado
            'pagos',         // bancos y conciliación
            'cliente',       // portal de cliente
            'proveedor',     // portal de proveedor
        ] as $rol) {
            Role::findOrCreate($rol, 'web');
        }

        $this->primeraCuenta();
    }

    private function primeraCuenta(): void
    {
        $usuario = trim((string) env('DEV_ADMIN_USER'));
        $clave = (string) env('DEV_ADMIN_PASSWORD');

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
        User::updateOrCreate(
            ['username' => $usuario],
            [
                'name' => 'Administrador',
                'email' => $usuario.'@'.parse_url((string) config('app.url'), PHP_URL_HOST),
                'password' => Hash::make($clave),
                'status' => 1,
                'role' => User::ROLE_SUPER_ADMIN,
                'access' => User::ACCESS_INTERNAL,
            ]
        )->syncRoles(['super-admin']);

        $this->command?->info("Cuenta inicial `{$usuario}` lista.");
    }
}
