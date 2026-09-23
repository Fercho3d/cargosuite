<?php

namespace App\Support\Workshop;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El almacén de refacciones.
 *
 * ⚠️ **Toda existencia se mueve por aquí.** `refaccion.existencia` está
 * desnormalizada porque el listado no puede sumar el kárdex en cada renglón,
 * pero la verdad es el movimiento: si alguien escribe la columna a mano, el
 * inventario deja de cuadrar y nadie sabe desde cuándo. `MovimientosTest` vigila
 * que la columna y la suma del kárdex no se separen.
 */
class Inventory
{
    public const TIPOS = ['entrada', 'salida', 'ajuste'];

    /**
     * Registra un movimiento y deja la existencia al día.
     *
     * · `entrada` suma y **actualiza el costo** de la refacción: es la compra.
     * · `salida` resta y no toca el costo.
     * · `ajuste` deja la existencia en la cantidad indicada —lo que salga del
     *   conteo físico— y guarda la diferencia, que es lo que se audita.
     *
     * @param  array<string, mixed>  $extra  mantenimiento_id, provider_id, folio, notas
     */
    public static function mueve(
        int $refaccion,
        string $tipo,
        float $cantidad,
        ?float $costo = null,
        ?string $fecha = null,
        array $extra = [],
        ?int $usuario = null,
    ): void {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new RuntimeException("Movimiento de almacén desconocido: «{$tipo}».");
        }

        DB::transaction(function () use ($refaccion, $tipo, $cantidad, $costo, $fecha, $extra, $usuario): void {
            // Se bloquea la fila: dos salidas a la vez sobre la misma refacción
            // leerían la misma existencia y una de las dos se perdería.
            $actual = DB::table('refaccion')->where('refaccion_id', $refaccion)->lockForUpdate()->first();

            if ($actual === null) {
                throw new RuntimeException("No existe la refacción {$refaccion}.");
            }

            $existencia = (float) $actual->existencia;

            $movida = match ($tipo) {
                'entrada' => $cantidad,
                'salida' => -$cantidad,
                default => $cantidad - $existencia,
            };

            $cambios = ['existencia' => round($existencia + $movida, 2)];

            // El costo solo lo mueve una compra: valuar una salida con el precio
            // de la siguiente compra falsea el costo de la orden.
            if ($tipo === 'entrada' && $costo !== null && $costo > 0) {
                $cambios['costo'] = $costo;
            }

            DB::table('refaccion')->where('refaccion_id', $refaccion)->update($cambios);

            DB::table('movimiento_refaccion')->insert([
                'refaccion_id' => $refaccion,
                'tipo' => $tipo,
                'cantidad' => round($movida, 2),
                'costo' => $costo ?? (float) $actual->costo,
                'fecha' => $fecha ?: now()->toDateString(),
                'mantenimiento_id' => $extra['mantenimiento_id'] ?? null,
                'provider_id' => $extra['provider_id'] ?? null,
                'folio' => $extra['folio'] ?? null,
                'notas' => $extra['notas'] ?? null,
                'created_by' => $usuario,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Lo que está por acabarse: existencia en o por debajo del mínimo.
     *
     * Es para lo que sirve un almacén. Saber qué hay lo dice cualquier lista;
     * lo que detiene un camión es enterarse de que no hay cuando hace falta.
     *
     * @return Collection<int, object>
     */
    public static function bajoMinimo(): Collection
    {
        return DB::table('refaccion')
            ->where('activo', 1)
            ->where('minimo', '>', 0)
            ->whereColumn('existencia', '<=', 'minimo')
            ->orderBy('nombre')
            ->get();
    }

    /** Kárdex de una refacción: de dónde vino y a dónde se fue cada pieza. */
    public static function kardex(int $refaccion, int $limite = 50): Collection
    {
        return DB::table('movimiento_refaccion as m')
            ->leftJoin('mantenimiento as t', 't.mantenimiento_id', '=', 'm.mantenimiento_id')
            ->leftJoin('unidad as u', 'u.unidad_id', '=', 't.unidad_id')
            ->leftJoin('provider as p', 'p.provider_id', '=', 'm.provider_id')
            ->where('m.refaccion_id', $refaccion)
            ->orderByDesc('m.movimiento_id')
            ->limit($limite)
            ->get(['m.*', 't.folio as orden', 'u.numero as unidad', 'p.fullName as proveedor']);
    }

    /** Lo que vale el almacén, al último costo de compra. */
    public static function valor(): float
    {
        return round((float) DB::table('refaccion')->where('activo', 1)
            ->selectRaw('SUM(existencia * costo) as v')->value('v'), 2);
    }

    /** Comprobación de sanidad: existencia contra la suma del kárdex. */
    public static function descuadres(): Collection
    {
        return DB::table('refaccion as r')
            ->leftJoin('movimiento_refaccion as m', 'm.refaccion_id', '=', 'r.refaccion_id')
            ->groupBy('r.refaccion_id', 'r.codigo', 'r.nombre', 'r.existencia')
            ->havingRaw('ABS(r.existencia - IFNULL(SUM(m.cantidad), 0)) > 0.001')
            ->get(['r.refaccion_id', 'r.codigo', 'r.nombre', 'r.existencia',
                DB::raw('IFNULL(SUM(m.cantidad), 0) as kardex')]);
    }
}
