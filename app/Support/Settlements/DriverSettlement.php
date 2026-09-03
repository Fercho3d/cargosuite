<?php

namespace App\Support\Settlements;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La liquidación de un operador: lo que ganó por sus viajes, menos lo que se le
 * descuenta.
 *
 * ⚠️ **No es nómina fiscal.** No calcula IMSS ni ISR ni timbra nada; eso se
 * exporta al sistema de nómina de la empresa. Aquí vive lo operativo, que es lo
 * que el dueño de camiones pide cuando dice «la nómina de los operadores».
 */
class DriverSettlement
{
    /**
     * Los viajes de un operador en el periodo que **todavía no están en otra
     * liquidación**.
     *
     * Esa condición es el corazón del módulo: sin ella, dos liquidaciones que se
     * traslapen le pagarían dos veces el mismo viaje, y eso no se descubre hasta
     * que alguien cuadra el mes.
     *
     * @return Collection<int, object>
     */
    public static function viajesPendientes(int $operador, string $desde, string $hasta): Collection
    {
        return DB::table('booking as b')
            ->leftJoin('client as c', 'c.client_id', '=', 'b.client')
            ->leftJoin('loading_ports as lp', 'lp.port_id', '=', 'b.loading_port')
            ->leftJoin('dicharge_port as dp', 'dp.dicharge_port_id', '=', 'b.dicharge_port_id')
            ->where('b.operador_id', $operador)
            ->where('b.is_draft', 0)
            ->whereBetween('b.loading_EDT', [$desde, $hasta])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('liquidacion_renglon as lr')
                ->whereColumn('lr.booking', 'b.booking_id'))
            ->orderBy('b.loading_EDT')
            ->get([
                'b.booking_id', 'b.booking_number', 'b.loading_EDT',
                'c.fullName as cliente', 'lp.port_name as origen', 'dp.name as destino',
            ]);
    }

    /**
     * Lo que se le propone pagar por un viaje, según su tarifa.
     *
     * `fijo` es un monto por viaje; `porcentaje` se calcula sobre lo que se le
     * facturó al cliente por ese viaje, que es como se paga en buena parte del
     * autotransporte. Sin tarifa capturada se propone cero y lo teclea quien
     * liquida — mejor un cero visible que un número inventado.
     */
    public static function propuestaPorViaje(object $operador, int $booking): float
    {
        $tipo = $operador->tarifa_tipo ?? null;
        $valor = (float) ($operador->tarifa_valor ?? 0);

        if ($valor <= 0 || $tipo === null) {
            return 0.0;
        }

        if ($tipo === 'fijo') {
            return round($valor, 2);
        }

        // Porcentaje sobre lo facturado del viaje, sin impuestos: el operador
        // cobra sobre el flete, no sobre el IVA.
        $facturado = (float) DB::table('charge as ch')
            ->join('transaction as t', 't.transc_id', '=', 'ch.transaction')
            ->where('t.booking', $booking)
            ->where('t.tran_type', 0)
            ->where('t.active', 1)
            ->where('t.cancelled', 0)
            ->sum(DB::raw('ch.price * ch.quantity'));

        return round($facturado * ($valor / 100), 2);
    }

    /**
     * Totales de una liquidación.
     *
     * @return array{percepciones: float, deducciones: float, total: float}
     */
    public static function totales(int $liquidacion): array
    {
        $filas = DB::table('liquidacion_renglon')
            ->where('liquidacion_id', $liquidacion)
            ->selectRaw('tipo, SUM(importe) as suma')
            ->groupBy('tipo')
            ->pluck('suma', 'tipo');

        $percepciones = round((float) ($filas['percepcion'] ?? 0), 2);
        $deducciones = round((float) ($filas['deduccion'] ?? 0), 2);

        return [
            'percepciones' => $percepciones,
            'deducciones' => $deducciones,
            'total' => round($percepciones - $deducciones, 2),
        ];
    }

    /** El siguiente folio, continuo y sin huecos aunque se borre alguna. */
    public static function siguienteNumero(): string
    {
        $ultimo = (int) DB::table('liquidacion')->max('liquidacion_id');

        return 'LQ-'.str_pad((string) ($ultimo + 1), 5, '0', STR_PAD_LEFT);
    }
}
