<?php

namespace App\Support\Workshop;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mantenimiento de las unidades.
 *
 * Una orden dice qué se le hizo a qué unidad, con qué kilometraje y qué le
 * costó: mano de obra más las refacciones que se le pusieron, **descontadas del
 * almacén** al ponerlas (ver `Inventory`).
 *
 * Lo que de verdad se vende de este módulo no es el historial, es el aviso: qué
 * unidades ya deben servicio. Un preventivo que se avisa tarde es un correctivo.
 */
class Maintenance
{
    /** Cada cuántos kilómetros toca servicio si la unidad no lo tiene puesto. */
    public const CADA_KM = 20000;

    /**
     * A qué parte del intervalo se empieza a avisar: el último 10 %.
     *
     * Proporcional y no fijo, porque no toda unidad se sirve cada 20 000: sobre
     * un intervalo de 10 000 km, avisar 2 000 antes es avisar a la quinta parte
     * del ciclo, y sobre uno de 40 000 se avisaría tardísimo.
     */
    private const AVISO = 0.10;

    public static function siguienteFolio(): string
    {
        return 'OT-'.str_pad((string) ((int) DB::table('mantenimiento')->max('mantenimiento_id') + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @return array{mano_obra: float, refacciones: float, total: float, piezas: int} */
    public static function totales(int $mantenimiento): array
    {
        $orden = DB::table('mantenimiento')->where('mantenimiento_id', $mantenimiento)->first();

        $refacciones = DB::table('mantenimiento_refaccion')
            ->where('mantenimiento_id', $mantenimiento)
            ->selectRaw('SUM(cantidad * costo) as importe, COUNT(*) as piezas')
            ->first();

        $manoObra = (float) ($orden->mano_obra ?? 0);
        $partes = round((float) ($refacciones->importe ?? 0), 2);

        return [
            'mano_obra' => $manoObra,
            'refacciones' => $partes,
            'total' => round($manoObra + $partes, 2),
            'piezas' => (int) ($refacciones->piezas ?? 0),
        ];
    }

    /** Las refacciones de una orden, con su nombre. */
    public static function refacciones(int $mantenimiento): Collection
    {
        return DB::table('mantenimiento_refaccion as mr')
            ->join('refaccion as r', 'r.refaccion_id', '=', 'mr.refaccion_id')
            ->where('mr.mantenimiento_id', $mantenimiento)
            ->orderBy('mr.renglon_id')
            ->get(['mr.renglon_id', 'mr.refaccion_id', 'mr.cantidad', 'mr.costo',
                'r.codigo', 'r.nombre', 'r.medida']);
    }

    /**
     * Qué unidades deben servicio, y cuánto les falta o cuánto se pasaron.
     *
     * `recorridos` son los kilómetros desde el último servicio; si nunca se le
     * ha hecho uno, se cuenta desde cero, que es lo prudente con una unidad
     * recién dada de alta.
     *
     * @return Collection<int, object>
     */
    public static function porServicio(): Collection
    {
        return DB::table('unidad')
            ->where('activo', 1)
            ->where('tipo', 'tractor')
            ->orderBy('numero')
            ->get()
            ->map(function (object $unidad): object {
                $cada = (int) ($unidad->servicio_cada_km ?: self::CADA_KM);
                $recorridos = max(0, (int) $unidad->kilometraje - (int) ($unidad->ultimo_servicio_km ?? 0));

                $unidad->cada_km = $cada;
                $unidad->recorridos = $recorridos;
                $unidad->faltan = $cada - $recorridos;
                $unidad->vencido = $unidad->faltan <= 0;
                $unidad->proximo = ! $unidad->vencido && $unidad->faltan <= $cada * self::AVISO;

                return $unidad;
            })
            ->filter(fn (object $unidad) => $unidad->vencido || $unidad->proximo)
            ->sortBy('faltan')
            ->values();
    }

    /**
     * Cierra la orden y **le pone el reloj a cero a la unidad**.
     *
     * Es el paso que hace que el aviso sirva: sin esto, la unidad que acaba de
     * salir del taller seguiría saliendo en la lista de las que deben servicio.
     */
    public static function cierra(int $mantenimiento, ?string $salida = null, ?int $usuario = null): void
    {
        $orden = DB::table('mantenimiento')->where('mantenimiento_id', $mantenimiento)->first();

        if ($orden === null || $orden->estado === 'cerrado') {
            return;
        }

        DB::transaction(function () use ($orden, $mantenimiento, $salida): void {
            DB::table('mantenimiento')->where('mantenimiento_id', $mantenimiento)->update([
                'estado' => 'cerrado',
                'salida' => $salida ?: now()->toDateString(),
            ]);

            // Solo el preventivo reinicia el contador: un correctivo —una llanta,
            // una manguera— no es el servicio que toca cada 20 000 kilómetros.
            if ($orden->tipo !== 'preventivo' || $orden->odometro === null) {
                return;
            }

            $unidad = DB::table('unidad')->where('unidad_id', $orden->unidad_id)->first();

            DB::table('unidad')->where('unidad_id', $orden->unidad_id)->update([
                'ultimo_servicio_km' => $orden->odometro,
                'ultimo_servicio' => $salida ?: now()->toDateString(),
                // El odómetro de la orden manda si va por delante: la unidad rodó
                // desde la última captura.
                'kilometraje' => max((int) ($unidad->kilometraje ?? 0), (int) $orden->odometro),
            ]);
        });
    }

    /** Historial y gasto de una unidad. */
    public static function historial(int $unidad, int $limite = 20): Collection
    {
        return DB::table('mantenimiento')
            ->where('unidad_id', $unidad)
            ->orderByDesc('entrada')
            ->limit($limite)
            ->get()
            ->map(function (object $orden): object {
                $orden->total = self::totales((int) $orden->mantenimiento_id)['total'];

                return $orden;
            });
    }

    /** Lo gastado en mantenimiento por unidad, para saber cuál sale cara. */
    public static function gastoPorUnidad(?string $desde = null, ?string $hasta = null): Collection
    {
        // Las refacciones se agregan ANTES de unir: unidas en crudo, una orden
        // con tres refacciones repetiría su mano de obra tres veces.
        $refacciones = DB::table('mantenimiento_refaccion')
            ->selectRaw('mantenimiento_id, SUM(cantidad * costo) as importe')
            ->groupBy('mantenimiento_id');

        return DB::table('mantenimiento as t')
            ->join('unidad as u', 'u.unidad_id', '=', 't.unidad_id')
            ->leftJoinSub($refacciones, 'r', 'r.mantenimiento_id', '=', 't.mantenimiento_id')
            ->when($desde, fn ($q) => $q->where('t.entrada', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('t.entrada', '<=', $hasta))
            ->groupBy('u.unidad_id', 'u.numero', 'u.marca')
            ->orderByDesc('total')
            ->get([
                'u.unidad_id', 'u.numero', 'u.marca',
                DB::raw('COUNT(*) as ordenes'),
                DB::raw('ROUND(SUM(t.mano_obra + IFNULL(r.importe, 0)), 2) as total'),
            ]);
    }
}
