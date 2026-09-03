<?php

namespace Tests\Feature\Transactions;

use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Paridad exacta contra el sistema original.
 *
 * Para cada escenario se ejecuta el SQL que produce `TransactionSearch` de Yii2
 * —capturado tal cual de la aplicación real, ver `tests/Fixtures/`— y el que
 * produce `TransactionQuery`, sobre la MISMA base de datos, y se comparan renglón
 * por renglón y columna por columna.
 *
 * Es la prueba que respalda la promesa de la migración: mismo número, otro tiempo.
 * Corre contra la base local `frego` con datos reales, así que es lenta a propósito.
 *
 * Para regenerar los escenarios:
 *   php tools/export_legacy_sql.php
 */
#[Group('parity')]
class QueryParityTest extends LegacyDatabaseTestCase
{
    /**
     * Columnas de dinero deterministas: son `SUM(CASE …)` evaluados fila por fila,
     * así que su valor no depende del plan de ejecución. Se comparan siempre.
     */
    private const AGGREGATE_COLUMNS = [
        'non_dec',
        'sub_16_mxn',
        'sub_0_mxn',
        'tax_16_mxn',
        'tax_ret_mxn',
        'tax_0_mxn',
        'amount_original',
        'amount_original_mxn',
        'total_amount',
        'income',
        'expense',
    ];

    /**
     * Columnas que dependen de UNA fila del grupo (no van dentro del SUM ni en el
     * GROUP BY): el tipo de cambio, lo pagado y los totales cuyo CASE mira
     * `tran_type`. Al agrupar por transacción hay una sola fila y son exactas.
     *
     * Al agrupar por booking / proveedor / cliente, en cambio, MySQL elige una fila
     * cualquiera del grupo, y la elección depende del plan de ejecución. Eso las
     * vuelve no deterministas **en el sistema original**: dos ejecuciones con
     * planes distintos dan cifras distintas. No es algo que introduzca la
     * migración, y por eso no se comparan en los escenarios agrupados.
     */
    private const ROW_DEPENDENT_COLUMNS = [
        'amount_original_paid_mxn',
        'total_amount_paid_tc',
        'total_natural_amount',
        'left_to_pay',
        'tran_paid_amount',
        'sub_16_paid',
        'sub_0_paid',
        'tax_16_paid',
        'tax_ret_paid',
        'subtotal_VAT0',
        'subtotal_VAT16',
        'exchange_value',
        'paid_exchange_value',
    ];

    /** Columnas de identidad: solo tienen sentido al agrupar por transacción. */
    private const IDENTITY_COLUMNS = [
        'tran_number',
        'tran_type',
        'invoice_type',
        'booking',
        'booking_number',
        'currency',
        'companyName',
        'vendorName',
        'customerName',
        'seal',
        'cancelled',
    ];

    /**
     * Un centavo de tolerancia. Ambas consultas hacen la misma aritmética en el
     * mismo motor, así que en la práctica la diferencia es cero; el margen existe
     * para el redondeo del último bit de los DECIMAL/DOUBLE.
     */
    private const TOLERANCE = 0.005;

    public static function scenarios(): array
    {
        // El proveedor de datos corre antes de que arranque la aplicación,
        // así que no se puede usar base_path().
        $path = dirname(__DIR__, 2).'/Fixtures/legacy-transaction-sql.json';

        if (! file_exists($path)) {
            return ['fixture ausente' => ['__missing__']];
        }

        $cases = [];

        foreach (json_decode(file_get_contents($path), true) as $scenario) {
            $cases[$scenario['name']] = [$scenario];
        }

        return $cases;
    }

    #[DataProvider('scenarios')]
    public function test_devuelve_los_mismos_resultados_que_yii2(array|string $scenario): void
    {
        if ($scenario === '__missing__') {
            $this->markTestSkipped('Falta tests/Fixtures/legacy-transaction-sql.json (regenerar con export_legacy_sql.php).');
        }

        $key = $this->keyColumn($scenario['group_by']);

        $legacy = $this->index(DB::select($scenario['sql']), $key);
        $ours = $this->index(
            TransactionQuery::make(TransactionFilters::make($scenario['filters']))->get()->all(),
            $key
        );

        $this->assertSame(
            array_keys($legacy),
            array_keys($ours),
            "El escenario '{$scenario['name']}' devuelve otro conjunto de filas que Yii2."
        );

        // Solo al agrupar por transacción tiene sentido comparar las columnas que
        // toman el valor de una fila concreta del grupo.
        $perTransaction = $key === 'transc_id';

        $numeric = $perTransaction
            ? array_merge(self::AGGREGATE_COLUMNS, self::ROW_DEPENDENT_COLUMNS)
            : self::AGGREGATE_COLUMNS;

        foreach ($legacy as $id => $expected) {
            $actual = $ours[$id];

            foreach ($numeric as $column) {
                $this->assertNumericMatches($expected, $actual, $column, $scenario['name'], $id);
            }

            if (! $perTransaction) {
                continue;
            }

            foreach (self::IDENTITY_COLUMNS as $column) {
                $this->assertSame(
                    $this->normalize($expected->{$column} ?? null),
                    $this->normalize($actual->{$column} ?? null),
                    "[{$scenario['name']}] fila {$id}: la columna '{$column}' no coincide."
                );
            }
        }
    }

    private function assertNumericMatches(object $expected, object $actual, string $column, string $scenario, int|string $id): void
    {
        $a = $expected->{$column} ?? null;
        $b = $actual->{$column} ?? null;

        // Los NULL heredados (p. ej. transacciones en EUR sin tipo de cambio del día)
        // se conservan tal cual: si el original devuelve NULL, el nuevo también.
        if ($a === null || $b === null) {
            $this->assertSame(
                $a === null,
                $b === null,
                "[{$scenario}] fila {$id}: '{$column}' vale ".var_export($a, true).' en Yii2 y '.var_export($b, true).' aquí.'
            );

            return;
        }

        $this->assertEqualsWithDelta(
            (float) $a,
            (float) $b,
            self::TOLERANCE,
            "[{$scenario}] fila {$id}: '{$column}' vale {$a} en Yii2 y {$b} aquí."
        );
    }

    /** @return array<int|string, object> */
    private function index(array $rows, string $key): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $row = (object) $row;
            $indexed[$row->{$key} ?? 'NULL'] = $row;
        }

        ksort($indexed);

        return $indexed;
    }

    private function keyColumn(string $groupBy): string
    {
        return match ($groupBy) {
            'booking' => 'booking',
            'vendor' => 'vendor',
            'customer' => 'customer',
            default => 'transc_id',
        };
    }

    private function normalize(mixed $value): ?string
    {
        return $value === null ? null : trim((string) $value);
    }
}
