<?php

namespace Database\Seeders;

use App\Support\Milestones\MilestoneCatalog;
use Database\Seeders\Perfiles\PerfilDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo de hitos del expediente.
 *
 * ⚠️ **Existe porque una instalación nueva se quedaba sin hitos.** Las filas las
 * insertaba la migración que creó la tabla, y un `migrate` sobre una base vacía
 * arranca desde `database/schema/mysql-schema.sql`: el esquema entra de golpe,
 * las migraciones anteriores se dan por corridas y sus INSERT **nunca se
 * ejecutan**. Resultado: la lista de verificación salía vacía y no se podía
 * marcar nada, porque no había hito que marcar. Pasó en producción.
 *
 * Los datos de un catálogo, entonces, van en un sembrador y no en una migración.
 *
 *   php artisan db:seed --class=HitosSeeder
 */
class HitosSeeder extends Seeder
{
    private ?PerfilDemo $perfil = null;

    private bool $reemplazar = false;

    /** Los hitos de esta vertical (marítima, autotransporte, taller…). */
    public function conPerfil(PerfilDemo $perfil): static
    {
        $this->perfil = $perfil;

        return $this;
    }

    /** Cambiar de vertical cambia los pasos: fuera los anteriores. */
    public function reemplazando(): static
    {
        $this->reemplazar = true;

        return $this;
    }

    public function run(): void
    {
        if ($this->reemplazar) {
            DB::table('hito')->delete();
        } elseif (DB::table('hito')->exists()) {
            $this->command?->warn('El catálogo de hitos ya tiene filas: no se toca.');

            return;
        }

        $hitos = ($this->perfil ?? PerfilDemo::elegido())->hitos();

        foreach ($hitos as $i => $hito) {
            DB::table('hito')->insert([
                'clave' => $hito['clave'],
                'etiqueta' => $hito['etiqueta'],
                'orden' => ($i + 1) * 10,
                'activo' => 1,
                'columna_legado' => $hito['columna'],
            ]);
        }

        // El catálogo se recuerda por petición; sembrado a media petición —desde
        // el botón de ajustes— habría que volver a leerlo.
        MilestoneCatalog::olvida();

        $this->command?->info(count($hitos).' hitos en el catálogo.');
    }
}
