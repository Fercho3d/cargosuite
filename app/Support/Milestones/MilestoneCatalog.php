<?php

namespace App\Support\Milestones;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los hitos del expediente: qué pasos se siguen en este negocio.
 *
 * Antes eran columnas de `booking_continuity`, o sea que cambiarlos exigía tocar
 * el esquema. Ahora son un catálogo, y se capturan desde `/catalogos/hitos` como
 * cualquier otro.
 *
 * No se cachea: son catorce filas, y cachear catálogos en este sistema ya causó
 * dos caídas (ver `CatalogCacheTest`).
 */
class MilestoneCatalog
{
    /** Llave del recuerdo por petición dentro del contenedor. */
    private const MEMORIA = 'hitos.catalogo';

    /**
     * Los hitos activos, en orden, con la etiqueta ya traducida.
     *
     * @return Collection<int, object>
     */
    public static function activos(): Collection
    {
        return self::todos()->where('activo', true)->values();
    }

    /** @return Collection<int, object> */
    public static function todos(): Collection
    {
        // Se recuerda por petición, no entre peticiones: la misma pantalla lo
        // pide varias veces al pintar la rejilla.
        //
        // El recuerdo vive en el contenedor y NO en una propiedad estática: entre
        // pruebas la aplicación se rehace pero una estática sobrevive, y la
        // segunda prueba se encontraría el catálogo de la primera.
        if (app()->bound(self::MEMORIA)) {
            return app(self::MEMORIA);
        }

        $hitos = DB::table('hito')
            ->orderBy('orden')
            ->orderBy('hito_id')
            ->get()
            ->map(fn (object $hito) => (object) [
                'hito_id' => (int) $hito->hito_id,
                'clave' => (string) $hito->clave,
                'etiqueta' => __((string) $hito->etiqueta),
                'orden' => (int) $hito->orden,
                'activo' => (bool) $hito->activo,
                'columna_legado' => $hito->columna_legado,
            ]);

        app()->instance(self::MEMORIA, $hitos);

        return $hitos;
    }

    public static function porClave(string $clave): ?object
    {
        return self::todos()->firstWhere('clave', $clave);
    }

    /** @return array<string, string> clave => etiqueta */
    public static function etiquetas(): array
    {
        return self::activos()->pluck('etiqueta', 'clave')->all();
    }

    /** Se olvida lo recordado. Hay que llamarlo al cambiar el catálogo. */
    public static function olvida(): void
    {
        app()->forgetInstance(self::MEMORIA);
    }
}
