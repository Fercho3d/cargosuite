<?php

namespace Tests\Feature\Transactions;

use App\Models\Core\Transaction;
use App\Queries\ProfitByBooking;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Utilidad por booking: que sumar en la base dé lo mismo que sumaba el original
 * fila por fila en PHP.
 *
 * El algoritmo del original se reimplementa aquí tal cual (`sumaComoElOriginal`)
 * usando el mismo motor de consulta, que ya está verificado contra Yii2 celda por
 * celda. Así lo que se compara es exactamente el cambio: mover la suma de PHP a
 * SQL, sin que se mueva ningún número.
 *
 * El original tenía dos cálculos que no coincidían: `buildProfitSummary()` del
 * controlador (sumaba con signo, y restaba las notas de crédito al cliente) y
 * la pantalla del booking (`views/transaction/index.php`, magnitud por renglón
 * y sin las notas de crédito al cliente). Se unificó en el criterio de la
 * pantalla del booking, que es el que el cliente veía junto a cada factura, y
 * esta prueba codifica ese.
 */
#[Group('parity')]
class ProfitByBookingTest extends LegacyDatabaseTestCase
{
    /** Un mes con volumen de verdad: cientos de facturas y sus costos. */
    private const RANGO = '01/12/2022 - 31/12/2022';

    private function filtrosDeFacturas(): TransactionFilters
    {
        $filtros = TransactionFilters::make(['dates' => self::RANGO]);
        $filtros->type = [Transaction::TYPE_INVOICE];

        return $filtros;
    }

    public function test_la_utilidad_cuadra_con_el_algoritmo_original(): void
    {
        $enSql = (new ProfitByBooking($this->filtrosDeFacturas()))->summary();
        $enPhp = $this->sumaComoElOriginal();

        $this->assertNotSame([], $enSql['rows'], 'El rango de prueba se quedó sin datos.');
        $this->assertCount(count($enPhp), $enSql['rows'], 'Distinto número de bookings.');

        foreach ($enSql['rows'] as $fila) {
            $esperado = $enPhp[$fila['booking_id']] ?? null;

            $this->assertNotNull($esperado, "El booking {$fila['booking_id']} no está en el cálculo original.");

            foreach (['inv_doc', 'cost_doc', 'profit_doc', 'inv_pago', 'cost_pago', 'profit_pago'] as $columna) {
                $this->assertEqualsWithDelta(
                    round($esperado[$columna], 2),
                    round($fila[$columna], 2),
                    0.02,
                    "No cuadra {$columna} del booking {$fila['booking']}.",
                );
            }
        }
    }

    public function test_los_totales_son_la_suma_de_las_filas(): void
    {
        $resumen = (new ProfitByBooking($this->filtrosDeFacturas()))->summary();

        foreach ($resumen['totals'] as $columna => $total) {
            $this->assertEqualsWithDelta(
                round(array_sum(array_column($resumen['rows'], $columna)), 2),
                round($total, 2),
                0.02,
                "El total de {$columna} no es la suma de las filas.",
            );
        }
    }

    public function test_un_filtro_sin_resultados_devuelve_totales_en_cero(): void
    {
        $filtros = TransactionFilters::make(['dates' => '01/01/1990 - 31/01/1990']);
        $filtros->type = [Transaction::TYPE_INVOICE];

        $resumen = (new ProfitByBooking($filtros))->summary();

        $this->assertSame([], $resumen['rows']);
        $this->assertSame(0.0, $resumen['totals']['profit_doc']);
    }

    /**
     * Reimplementación literal del cálculo de `views/transaction/index.php` de
     * Yii2 (con el respaldo «si no hay pago, usa el documento» de
     * `buildProfitSummary()`, aplicado transacción por transacción): trae todas
     * las filas y acumula en PHP, `abs()` por renglón, las notas de crédito al
     * cliente fuera del ingreso y las de proveedor restando del costo.
     *
     * @return array<int, array<string, float>>
     */
    private function sumaComoElOriginal(): array
    {
        $bookings = [];

        foreach (TransactionQuery::make($this->filtrosDeFacturas())->get() as $fila) {
            $bookings[(int) $fila->booking_id] ??= [
                'inv_doc' => 0.0, 'inv_pago' => 0.0, 'cost_doc' => 0.0, 'cost_pago' => 0.0,
            ];
        }

        if ($bookings === []) {
            return [];
        }

        $ids = array_keys($bookings);

        $acumular = function (array $tipos, string $claveDoc, string $clavePago) use (&$bookings, $ids): void {
            $filtros = TransactionFilters::make([]);
            $filtros->type = $tipos;
            $filtros->booking_in = $ids;

            foreach (TransactionQuery::make($filtros)->get() as $fila) {
                $id = (int) $fila->booking_id;

                if (! isset($bookings[$id])) {
                    continue;
                }

                if ((int) $fila->tran_type === Transaction::TYPE_INVOICE && (int) $fila->invoice_type === Transaction::INVOICE_TYPE_CREDIT) {
                    continue;
                }

                $signo = (int) $fila->tran_type === Transaction::TYPE_CREDIT_BILL ? -1 : 1;
                $doc = abs((float) $fila->amount_original_mxn);
                $pago = abs((float) $fila->amount_original_paid_mxn);

                $bookings[$id][$claveDoc] += $signo * $doc;
                $bookings[$id][$clavePago] += $signo * ($pago != 0 ? $pago : $doc);
            }
        };

        $acumular([Transaction::TYPE_INVOICE], 'inv_doc', 'inv_pago');
        $acumular([Transaction::TYPE_BILL, Transaction::TYPE_CREDIT_BILL], 'cost_doc', 'cost_pago');

        return collect($bookings)->map(fn (array $b) => [
            'inv_doc' => $b['inv_doc'],
            'cost_doc' => $b['cost_doc'],
            'profit_doc' => $b['inv_doc'] - $b['cost_doc'],
            'inv_pago' => $b['inv_pago'],
            'cost_pago' => $b['cost_pago'],
            'profit_pago' => $b['inv_pago'] - $b['cost_pago'],
        ])->all();
    }
}
