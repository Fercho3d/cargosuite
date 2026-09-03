<?php

namespace App\Support\Fleet;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gastos de viaje: combustible, casetas y lo demás de carretera.
 *
 * Es la mitad que le faltaba a la rentabilidad. Hasta aquí el sistema sabía lo
 * que se le facturó al cliente y lo que costaron los proveedores, pero no lo
 * que se gastó en la carretera — que en autotransporte es donde se va el margen.
 */
class TripExpenses
{
    public const TIPOS = ['combustible', 'caseta', 'otro'];

    /** @return Collection<int, object> */
    public static function delViaje(int $booking): Collection
    {
        return DB::table('gasto_viaje as g')
            ->leftJoin('unidad as u', 'u.unidad_id', '=', 'g.unidad_id')
            ->leftJoin('provider as p', 'p.provider_id', '=', 'g.provider_id')
            ->where('g.booking', $booking)
            ->orderBy('g.fecha')
            ->orderBy('g.gasto_id')
            ->get(['g.*', 'u.numero as unidad', 'p.fullName as proveedor']);
    }

    /**
     * Totales del viaje, por tipo y en total.
     *
     * @return array{combustible: float, caseta: float, otro: float, total: float}
     */
    public static function totales(int $booking): array
    {
        $porTipo = DB::table('gasto_viaje')
            ->where('booking', $booking)
            ->selectRaw('tipo, SUM(importe) as suma')
            ->groupBy('tipo')
            ->pluck('suma', 'tipo');

        $totales = [];

        foreach (self::TIPOS as $tipo) {
            $totales[$tipo] = round((float) ($porTipo[$tipo] ?? 0), 2);
        }

        $totales['total'] = round(array_sum($totales), 2);

        return $totales;
    }

    /**
     * Rendimiento de una carga de combustible, en kilómetros por litro.
     *
     * Se calcula contra la **carga anterior de la misma unidad**: la diferencia
     * de odómetro entre las dos, dividida entre los litros de esta. Es la única
     * forma honesta de medirlo — con una sola carga no hay nada que comparar, y
     * por eso devuelve nulo en vez de un número inventado.
     *
     * Un rendimiento que se desploma de un mes a otro es lo que delata un robo
     * de diésel o un motor que empieza a fallar.
     */
    public static function rendimiento(object $carga): ?float
    {
        if ($carga->tipo !== 'combustible' || $carga->unidad_id === null) {
            return null;
        }

        $litros = (float) ($carga->litros ?? 0);
        $odometro = (int) ($carga->odometro ?? 0);

        if ($litros <= 0 || $odometro <= 0) {
            return null;
        }

        $anterior = DB::table('gasto_viaje')
            ->where('tipo', 'combustible')
            ->where('unidad_id', $carga->unidad_id)
            ->whereNotNull('odometro')
            ->where('odometro', '>', 0)
            ->where('odometro', '<', $odometro)
            ->orderByDesc('odometro')
            ->value('odometro');

        if ($anterior === null) {
            return null;
        }

        $kilometros = $odometro - (int) $anterior;

        // Un salto absurdo casi siempre es un dedazo en el odómetro, y ensuciaría
        // el promedio del mes entero. Mejor no dar número que dar uno falso.
        return $kilometros <= 0 || $kilometros > 5000 ? null : round($kilometros / $litros, 2);
    }
}
