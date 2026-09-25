<?php

namespace App\Support\Rutas;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que vale una ruta en una fecha: tarifa al cliente, costo con unidad propia,
 * costo subcontratado y el margen de cada caso.
 *
 * Todo precio se busca **vigente en la fecha**: el renglón más reciente cuyo
 * «vigente desde» no la pase. Lo anterior es histórico y no se borra.
 */
class Tarifario
{
    /** Precio del litro de diésel vigente en la fecha, o null si nunca se capturó. */
    public static function diesel(?string $fecha = null): ?object
    {
        return DB::table('precio_diesel')->where('fecha', '<=', $fecha ?? now()->toDateString())
            ->orderByDesc('fecha')->first();
    }

    /**
     * Los precios vigentes de la ruta, uno por tipo, concepto, cliente y
     * proveedor.
     *
     * @return Collection<int, object>
     */
    public static function vigentes(int $ruta, ?string $fecha = null): Collection
    {
        return DB::table('tarifa_ruta')->where('ruta_id', $ruta)
            ->where('vigente_desde', '<=', $fecha ?? now()->toDateString())
            ->orderByDesc('vigente_desde')->orderByDesc('tarifa_id')
            ->get()
            ->unique(fn (object $t) => $t->tipo.'|'.mb_strtolower($t->concepto).'|'.$t->client_id.'|'.$t->provider_id)
            ->values();
    }

    /** Litros × precio del día: lo que cuesta en diésel hacer la ruta con unidad propia. */
    public static function costoDiesel(object $ruta, ?object $diesel): ?float
    {
        if ($diesel === null || (float) $ruta->rendimiento <= 0) {
            return null;
        }

        return round((float) $ruta->km / (float) $ruta->rendimiento * (float) $diesel->precio, 2);
    }

    /**
     * Lo que se le cobra al cliente, por concepto: su tarifa especial si la
     * tiene y si no la general, en el orden de la general.
     *
     * @return list<object>
     */
    public static function ventaPorConcepto(Collection $vigentes, ?int $cliente = null): array
    {
        $venta = $vigentes->where('tipo', 'venta');
        $clave = fn (object $t) => mb_strtolower($t->concepto);
        $especiales = $cliente === null ? collect() : $venta->where('client_id', $cliente)->keyBy($clave);

        return $venta->whereNull('client_id')->sortBy('tarifa_id')->keyBy($clave)
            ->map(fn (object $general, string $concepto) => $especiales[$concepto] ?? $general)
            ->union($especiales)->values()->all();
    }

    public static function venta(Collection $vigentes, ?int $cliente = null): float
    {
        return round((float) collect(self::ventaPorConcepto($vigentes, $cliente))->sum('precio'), 2);
    }

    /**
     * El resumen de la ruta en la fecha, para la lista y la ficha.
     *
     * @return array{venta: float, diesel: ?float, costos: float, propio: ?float, subcontrato: ?float, margenPropio: ?float, margenSubcontrato: ?float}
     */
    public static function resumen(object $ruta, ?string $fecha = null, ?int $cliente = null): array
    {
        $vigentes = self::vigentes((int) $ruta->ruta_id, $fecha);
        $venta = self::venta($vigentes, $cliente);
        $diesel = self::costoDiesel($ruta, self::diesel($fecha));
        $costos = round((float) $vigentes->where('tipo', 'costo')->sum('precio'), 2);
        $propio = $diesel === null ? null : round($diesel + $costos, 2);
        // El subcontratista que se usaría es el más barato.
        $subcontrato = $vigentes->where('tipo', 'subcontrato')->min('precio');
        $subcontrato = $subcontrato === null ? null : (float) $subcontrato;

        $margen = fn (?float $costo) => $costo === null || $venta <= 0 ? null : round(($venta - $costo) / $venta * 100, 1);

        return [
            'venta' => $venta,
            'diesel' => $diesel,
            'costos' => $costos,
            'propio' => $propio,
            'subcontrato' => $subcontrato,
            'margenPropio' => $margen($propio),
            'margenSubcontrato' => $margen($subcontrato),
        ];
    }
}
