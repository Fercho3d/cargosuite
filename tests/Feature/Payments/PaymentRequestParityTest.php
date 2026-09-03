<?php

namespace Tests\Feature\Payments;

use App\Queries\PaymentRequestFilters;
use App\Queries\PaymentRequestQuery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Paridad exacta del motor de solicitudes de pago contra el sistema original.
 *
 * Mismo método que `QueryParityTest`: se ejecuta el SQL que produce
 * `PaymentRequestSearch` de Yii2 —capturado tal cual de la aplicación real— y el
 * que produce `PaymentRequestQuery`, sobre la MISMA base, y se comparan celda por
 * celda.
 *
 * Para regenerar los escenarios:
 *   php tools/export_legacy_payment_sql.php
 */
#[Group('parity')]
class PaymentRequestParityTest extends LegacyDatabaseTestCase
{
    /**
     * Columnas de dinero: son sumas evaluadas fila por fila, así que su valor no
     * depende del plan de ejecución y se comparan siempre.
     */
    private const AGGREGATE_COLUMNS = [
        'non_dec',
        'sub_16_paid',
        'sub_0_paid',
        'tax_16_paid',
        'tax_ret_paid',
        'total_paid',
        'total_to_pay',
        'diference',
        'amount_original_paid',
        'amount_original',
    ];

    /**
     * Columnas que salen de una fila cualquiera del grupo. Al agrupar por
     * solicitud hay una sola y son exactas; agrupando por cliente, proveedor o
     * tipo, la elección depende del plan de ejecución —también en el original— y
     * por eso no se comparan ahí.
     */
    private const ROW_DEPENDENT_COLUMNS = [
        'transc_id',
        'amount',
        'number',
        'bank_id',
        'bank_name',
        'exchange_value',
        'pay_tc',
        'amount_original_neg',
        'prefix',
        'providerName',
        'clientName',
    ];

    private const TOLERANCE = 0.005;

    public static function scenarios(): array
    {
        $path = dirname(__DIR__, 2).'/Fixtures/legacy-payment-request-sql.json';

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
            $this->markTestSkipped('Falta tests/Fixtures/legacy-payment-request-sql.json (regenerar con export_legacy_payment_sql.php).');
        }

        $agrupacion = $this->groupBy($scenario['filters']);
        $llave = $this->keyColumn($agrupacion);

        $nuestros = PaymentRequestQuery::make($this->filters($scenario['filters'], $agrupacion))->get()->all();

        try {
            $filasOriginales = DB::select($scenario['sql']);
        } catch (QueryException $e) {
            $this->assertLegacyDefect($e, $scenario['name'], $nuestros);

            return;
        }

        $legacy = $this->index($filasOriginales, $llave);
        $ours = $this->index($nuestros, $llave);

        $this->assertSame(
            array_keys($legacy),
            array_keys($ours),
            "El escenario '{$scenario['name']}' devuelve otro conjunto de filas que Yii2."
        );

        $columnas = $agrupacion === 'request'
            ? array_merge(self::AGGREGATE_COLUMNS, self::ROW_DEPENDENT_COLUMNS)
            : self::AGGREGATE_COLUMNS;

        foreach ($legacy as $id => $esperado) {
            foreach ($columnas as $columna) {
                $this->assertCellMatches($esperado, $ours[$id], $columna, $scenario['name'], $id);
            }
        }
    }

    /** Traduce el `groupBy` del original (columnas SQL) al nombre corto de acá. */
    private function groupBy(array $filtros): string
    {
        $columnas = $filtros['groupBy'] ?? ['payment_request.request_id'];

        return match ($columnas[0]) {
            'payment_request.client_id' => 'client',
            'payment_request.provider_id' => 'provider',
            'payment_request.type' => 'type',
            default => 'request',
        };
    }

    private function filters(array $legacy, string $agrupacion): PaymentRequestFilters
    {
        $filtros = PaymentRequestFilters::make(
            collect($legacy)->except('groupBy')->all()
        );

        $filtros->groupBy = $agrupacion;

        // `paid = 0` no puede perderse: `make()` descarta vacíos y el cero es un
        // filtro legítimo (solicitudes sin pagar).
        if (array_key_exists('paid', $legacy)) {
            $filtros->paid = (int) $legacy['paid'];
        }

        return $filtros;
    }

    /**
     * Columna (o columnas) que identifican un renglón.
     *
     * Al agrupar por proveedor, el original agrupa por proveedor **y divisa**: un
     * proveedor con pagos en dos monedas produce dos renglones, así que la clave
     * tiene que ser la pareja o las filas se pisarían entre sí.
     *
     * @return string|string[]
     */
    private function keyColumn(string $agrupacion): string|array
    {
        return match ($agrupacion) {
            'client' => 'client_id',
            'provider' => ['provider_id', 'account_id'],
            'type' => 'type',
            default => 'request_id',
        };
    }

    /**
     * Escenario que el sistema original no puede ejecutar.
     *
     * Filtrar estos reportes por número de solicitud arma un `WHERE request_id = …`
     * sin calificar, y como la columna existe en `payment_request` y en
     * `payments_by_transaction`, MySQL la rechaza por ambigua: la pantalla truena.
     * Es un defecto del sistema en operación, no de la traducción — aquí la
     * consulta sí corre, y eso es lo que se comprueba.
     */
    private function assertLegacyDefect(QueryException $e, string $escenario, array $nuestros): void
    {
        $this->assertStringContainsString(
            'ambiguous',
            $e->getMessage(),
            "El escenario '{$escenario}' falló en Yii2 por algo distinto al defecto conocido."
        );

        $this->addToAssertionCount(1);
    }

    private function assertCellMatches(object $esperado, object $actual, string $columna, string $escenario, int|string $id): void
    {
        $a = $esperado->{$columna} ?? null;
        $b = $actual->{$columna} ?? null;

        if ($a === null || $b === null) {
            $this->assertSame(
                $a === null,
                $b === null,
                "[{$escenario}] fila {$id}: '{$columna}' vale ".var_export($a, true).' en Yii2 y '.var_export($b, true).' aquí.'
            );

            return;
        }

        if (is_numeric($a) && is_numeric($b)) {
            $this->assertEqualsWithDelta(
                (float) $a,
                (float) $b,
                self::TOLERANCE,
                "[{$escenario}] fila {$id}: '{$columna}' vale {$a} en Yii2 y {$b} aquí."
            );

            return;
        }

        $this->assertSame(
            trim((string) $a),
            trim((string) $b),
            "[{$escenario}] fila {$id}: la columna '{$columna}' no coincide."
        );
    }

    /**
     * @param  string|string[]  $key
     * @return array<int|string, object>
     */
    private function index(array $rows, string|array $key): array
    {
        $columnas = (array) $key;
        $indexed = [];

        foreach ($rows as $row) {
            $row = (object) $row;

            $clave = implode('|', array_map(
                fn (string $columna) => $row->{$columna} ?? 'NULL',
                $columnas
            ));

            $indexed[$clave] = $row;
        }

        ksort($indexed);

        return $indexed;
    }
}
