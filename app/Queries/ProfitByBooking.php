<?php

namespace App\Queries;

use App\Models\Core\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Utilidad por booking para la pantalla de Facturas.
 *
 * Por cada booking que aparece facturado en el filtro actual:
 *  - **Utilidad a TC documento**: facturas menos costos, cada uno valuado al tipo
 *    de cambio del día de su documento.
 *  - **Utilidad a TC pago**: facturas al tipo de cambio del día en que se
 *    cobraron, menos costos al del día en que se pagaron. Lo que aún no tiene
 *    pago se valúa al del documento, para no perderlo de la suma.
 *
 * Dos reglas del original que parecen detalles y no lo son:
 *
 *  1. El filtro decide **qué bookings** se muestran, pero los importes se toman
 *     del **booking completo**, sin filtro de fecha. Varias facturas comparten
 *     los mismos costos, así que el balance solo cuadra si factura y costo cubren
 *     el mismo alcance.
 *  2. El respaldo «si no hay pago, usa el documento» se aplica **transacción por
 *     transacción**, no sobre el total del booking. Por eso la suma por booking
 *     se hace envolviendo la consulta por transacción, y no agrupando de una vez:
 *     un booking con una factura cobrada y otra sin cobrar daría otro número.
 *
 * Frente al original, que traía cada fila a PHP y sumaba ahí (tres consultas sin
 * paginar sobre todo el sistema), aquí las sumas las hace la base.
 */
class ProfitByBooking
{
    public function __construct(private TransactionFilters $filters) {}

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function summary(): array
    {
        $bookings = $this->filteredBookingIds();

        if ($bookings === []) {
            return ['rows' => [], 'totals' => $this->emptyTotals()];
        }

        $facturas = $this->amountsFor($bookings, [Transaction::TYPE_INVOICE]);
        $costos = $this->amountsFor($bookings, [Transaction::TYPE_BILL, Transaction::TYPE_CREDIT_BILL]);

        $rows = [];
        $totales = $this->emptyTotals();

        foreach ($facturas as $bookingId => $factura) {
            $costo = $costos->get($bookingId);

            // Los costos vienen con signo negativo del motor; la utilidad se
            // calcula contra su magnitud, igual que el original.
            $fila = [
                'booking_id' => $bookingId,
                'booking' => trim((string) $factura->booking_number),
                'inv_doc' => (float) $factura->doc,
                'cost_doc' => abs((float) ($costo->doc ?? 0)),
                'inv_pago' => (float) $factura->pago,
                'cost_pago' => abs((float) ($costo->pago ?? 0)),
            ];

            $fila['profit_doc'] = $fila['inv_doc'] - $fila['cost_doc'];
            $fila['profit_pago'] = $fila['inv_pago'] - $fila['cost_pago'];

            $rows[] = $fila;

            foreach ($totales as $clave => $valor) {
                $totales[$clave] = $valor + $fila[$clave];
            }
        }

        return ['rows' => $rows, 'totals' => $totales];
    }

    /** @return array<string, float> */
    private function emptyTotals(): array
    {
        return [
            'inv_doc' => 0.0,
            'cost_doc' => 0.0,
            'profit_doc' => 0.0,
            'inv_pago' => 0.0,
            'cost_pago' => 0.0,
            'profit_pago' => 0.0,
        ];
    }

    /**
     * Bookings que tienen alguna factura dentro del filtro actual.
     *
     * Se resuelve sobre la consulta completa —no sobre la fase de IDs— porque
     * algunos filtros (pagada, parcial, sin pagar) se aplican hasta el `HAVING`
     * y solo así se descartan los bookings que no cumplen.
     *
     * @return int[]
     */
    private function filteredBookingIds(): array
    {
        $inner = TransactionQuery::make($this->filters)->aggregateQuery()->reorder();

        return DB::table(DB::raw('('.$inner->toSql().') AS agg'))
            ->mergeBindings($inner)
            ->distinct()
            ->pluck('agg.booking_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Importes sin IVA por booking, ya con el respaldo por transacción aplicado.
     *
     * @param  int[]  $bookings
     * @param  int[]  $tipos
     * @return Collection<int, object>
     */
    private function amountsFor(array $bookings, array $tipos): Collection
    {
        $filtros = TransactionFilters::make([]);
        $filtros->type = $tipos;
        $filtros->booking_in = $bookings;

        $inner = TransactionQuery::make($filtros)->aggregateQuery()->reorder();

        return collect(
            DB::table(DB::raw('('.$inner->toSql().') AS agg'))
                ->mergeBindings($inner)
                ->selectRaw(<<<'SQL'
                    agg.booking_id,
                    agg.booking_number,
                    SUM(agg.amount_original_mxn) AS doc,
                    SUM(CASE WHEN agg.amount_original_paid_mxn <> 0
                             THEN agg.amount_original_paid_mxn
                             ELSE agg.amount_original_mxn END) AS pago
                SQL)
                ->groupBy('agg.booking_id', 'agg.booking_number')
                // El orden del original lo dictaba el de la pantalla; aquí se fija
                // por número de booking para que el reporte sea reproducible.
                ->orderBy('agg.booking_number')
                ->get()
        )->keyBy('booking_id');
    }
}
