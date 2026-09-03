<?php

namespace Tests\Feature\Transactions;

use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Aritmética del módulo con datos diminutos hechos a mano.
 *
 * Aquí los números esperados están calculados a lápiz, no copiados del sistema
 * viejo: si `TransactionQuery` y el original estuvieran mal de la misma forma,
 * las pruebas de paridad no lo notarían y estas sí.
 *
 * El escenario cabe en la cabeza:
 *   - T1 factura al cliente en USD (TC 20), cobrada a la mitad con TC de pago 18.
 *   - T2 costo de proveedor en USD (TC 18), con un cargo no deducible.
 *   - T3 nota de crédito de proveedor en pesos.
 *   - T4 factura en USD SIN tipo de cambio ese día: sus montos quedan en NULL,
 *     igual que en el sistema original.
 */
class TransactionMathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        $this->seedFixture();
    }

    private function seedFixture(): void
    {
        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN'],
            ['account_id' => 2, 'account_name' => 'Dólares', 'default' => null, 'prefix' => 'USD'],
        ]);

        DB::table('exchange')->insert([
            ['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2021-01-01', 'account' => 1],
            ['exchange_id' => 2, 'exchange_value' => 20, 'date_exchange' => '2025-01-10', 'account' => 2],
            ['exchange_id' => 3, 'exchange_value' => 18, 'date_exchange' => '2025-02-10', 'account' => 2],
        ]);

        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'IVA 16', 'tax_rate' => 0.16, 'tax_retention' => 0, 'non_deductible' => 0],
            ['charge_type_id' => 2, 'charge_type_name' => 'IVA 0', 'tax_rate' => 0, 'tax_retention' => 0, 'non_deductible' => 0],
            ['charge_type_id' => 3, 'charge_type_name' => 'No deducible', 'tax_rate' => 0, 'tax_retention' => 0, 'non_deductible' => 1],
            ['charge_type_id' => 4, 'charge_type_name' => 'IVA 16 con ret', 'tax_rate' => 0.16, 'tax_retention' => 0.04, 'non_deductible' => 0],
        ]);

        DB::table('company')->insert(['company_id' => 1, 'name' => 'FTA', 'rfc' => 'AAA010101AAA']);
        DB::table('booking')->insert(['booking_id' => 1, 'booking_number' => 'BK-1', 'mode' => 10, 'loading_EDT' => '2025-01-05']);
        DB::table('client')->insert(['client_id' => 1, 'fullName' => 'CLIENTE UNO', 'email' => 'cliente@example.test']);
        DB::table('provider')->insert(['provider_id' => 1, 'fullName' => 'PROVEEDOR UNO']);

        // Todas las filas deben traer las mismas columnas: el insert masivo arma
        // una sola lista de columnas para el lote.
        $base = [
            'company_id' => 1, 'booking' => 1, 'invoice_type' => 1, 'cancelled' => 0,
            'pdf_attach' => '', 'vendor' => null, 'customer' => null,
        ];

        DB::table('transaction')->insert([
            // Factura al cliente: 200 con IVA 16 % + 50 sin IVA, en USD a 20.
            ['transc_id' => 1, 'tran_number' => 'F-1', 'tran_type' => 0, 'account' => 2, 'tran_date' => '2025-01-10', 'customer' => 1] + $base,
            // Costo de proveedor: 100 con IVA 16 % + 10 no deducible, en USD a 18.
            ['transc_id' => 2, 'tran_number' => 'B-1', 'tran_type' => 1, 'account' => 2, 'tran_date' => '2025-02-10', 'vendor' => 1] + $base,
            // Nota de crédito de proveedor, en pesos.
            ['transc_id' => 3, 'tran_number' => 'CB-1', 'tran_type' => 2, 'account' => 1, 'tran_date' => '2025-02-10', 'vendor' => 1] + $base,
            // Factura en USD en un día SIN tipo de cambio capturado.
            ['transc_id' => 4, 'tran_number' => 'F-2', 'tran_type' => 0, 'account' => 2, 'tran_date' => '2025-03-10', 'customer' => 1] + $base,
        ]);

        DB::table('charge')->insert([
            ['charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 2, 'price' => 100, 'description' => 'Flete'],
            ['charge_id' => 2, 'transaction' => 1, 'type' => 2, 'quantity' => 1, 'price' => 50, 'description' => 'Maniobra'],
            ['charge_id' => 3, 'transaction' => 2, 'type' => 1, 'quantity' => 1, 'price' => 100, 'description' => 'Costo flete'],
            ['charge_id' => 4, 'transaction' => 2, 'type' => 3, 'quantity' => 1, 'price' => 10, 'description' => 'Propina'],
            ['charge_id' => 5, 'transaction' => 3, 'type' => 1, 'quantity' => 1, 'price' => -50, 'description' => 'Devolución'],
            ['charge_id' => 6, 'transaction' => 4, 'type' => 4, 'quantity' => 1, 'price' => 1000, 'description' => 'Servicio con retención'],
        ]);

        // Cobro a la mitad de la factura T1, en USD, el día en que el TC vale 18.
        DB::table('payment_request')->insert([
            'request_id' => 1, 'number' => 'PR-1', 'amount' => 141, 'paid' => 1,
            'client_id' => 1, 'currency_id' => 2, 'type' => 1, 'date' => '2025-02-10',
        ]);

        DB::table('payments_by_transaction')->insert([
            'request_id' => 1, 'transc_id' => 1, 'amount' => 141, 'paid' => 1,
        ]);
    }

    /** @return object[] indexado por transc_id */
    private function rows(array $filters = []): array
    {
        $rows = TransactionQuery::make(TransactionFilters::make($filters))->get();

        return $rows->keyBy('transc_id')->all();
    }

    /**
     * IDs que devuelve el filtro, ordenados de menor a mayor.
     * El orden de la consulta es descendente por ID; aquí interesa el conjunto.
     *
     * @return int[]
     */
    private function ids(array $filters = []): array
    {
        $ids = array_keys($this->rows($filters));
        sort($ids);

        return $ids;
    }

    public function test_factura_al_cliente_convierte_al_tipo_de_cambio_del_dia(): void
    {
        $t = $this->rows()[1];

        $this->assertEquals(20, $t->exchange_value, 'TC del documento');
        $this->assertEquals(200, $t->subtotal_VAT16, 'subtotal gravado al 16 %');
        $this->assertEquals(50, $t->subtotal_VAT0, 'subtotal sin IVA');
        $this->assertEquals(250, $t->amount_original, 'subtotal en su moneda');

        // (200 + 50 + 32 de IVA) x 20
        $this->assertEqualsWithDelta(5640, (float) $t->total_amount, 0.001);
        $this->assertEqualsWithDelta(4000, (float) $t->sub_16_mxn, 0.001);
        $this->assertEqualsWithDelta(1000, (float) $t->sub_0_mxn, 0.001);
        $this->assertEqualsWithDelta(640, (float) $t->tax_16_mxn, 0.001);
        $this->assertEqualsWithDelta(5000, (float) $t->amount_original_mxn, 0.001);
        $this->assertEqualsWithDelta(5000, (float) $t->income, 0.001);
        $this->assertEqualsWithDelta(0, (float) $t->expense, 0.001);
    }

    public function test_el_costo_de_proveedor_entra_con_signo_negativo(): void
    {
        $t = $this->rows()[2];

        // 10 no deducible + 100 gravado + 16 de IVA = 126, a 18, restando.
        $this->assertEqualsWithDelta(-2268, (float) $t->total_amount, 0.001);
        $this->assertEqualsWithDelta(-180, (float) $t->non_dec, 0.001);
        $this->assertEqualsWithDelta(-1800, (float) $t->sub_16_mxn, 0.001);
        $this->assertEqualsWithDelta(110, (float) $t->amount_original, 0.001, 'el subtotal en su moneda va sin signo');
        $this->assertEqualsWithDelta(1980, (float) $t->expense, 0.001);
        $this->assertEqualsWithDelta(0, (float) $t->income, 0.001);
    }

    public function test_la_nota_de_credito_de_proveedor_suma_en_vez_de_restar(): void
    {
        $t = $this->rows()[3];

        // tran_type = 2 cae en la rama positiva: el importe negativo se queda negativo.
        $this->assertEquals(1, $t->exchange_value, 'la moneda base no se convierte');
        $this->assertEqualsWithDelta(-58, (float) $t->total_amount, 0.001);
        $this->assertEqualsWithDelta(-58, (float) $t->total_natural_amount, 0.001);
    }

    public function test_sin_tipo_de_cambio_del_dia_los_montos_quedan_en_null(): void
    {
        $t = $this->rows()[4];

        $this->assertNull($t->exchange_value, 'no hay TC capturado para ese día');
        $this->assertNull($t->total_amount, 'el original tampoco inventa un TC: propaga el NULL');
        $this->assertNull($t->amount_original_mxn);

        // Lo que no depende del TC sí tiene valor: 1000 + 160 de IVA - 40 de retención.
        $this->assertEqualsWithDelta(1000, (float) $t->amount_original, 0.001);
        $this->assertEqualsWithDelta(1120, (float) $t->total_natural_amount, 0.001);
    }

    public function test_el_cobro_parcial_se_prorratea_entre_las_cubetas(): void
    {
        $t = $this->rows()[1];

        // Se cobraron 141 de 282: exactamente la mitad de cada concepto.
        $this->assertEqualsWithDelta(141, (float) $t->tran_paid_amount, 0.001);
        $this->assertEqualsWithDelta(100, (float) $t->sub_16_paid, 0.001);
        $this->assertEqualsWithDelta(25, (float) $t->sub_0_paid, 0.001);
        $this->assertEqualsWithDelta(16, (float) $t->tax_16_paid, 0.001);
        $this->assertEqualsWithDelta(0, (float) $t->tax_ret_paid, 0.001);
        $this->assertEqualsWithDelta(141, (float) $t->left_to_pay, 0.001, 'queda por cobrar la otra mitad');
    }

    public function test_valua_al_tipo_de_cambio_del_dia_en_que_se_cobro(): void
    {
        $t = $this->rows()[1];

        // La factura se emitió a 20 y se cobró a 18: son dos valuaciones distintas.
        $this->assertEqualsWithDelta(18, (float) $t->paid_exchange_value, 0.001);
        $this->assertEqualsWithDelta(282 * 18, (float) $t->total_amount_paid_tc, 0.001);
        $this->assertEqualsWithDelta(250 * 18, (float) $t->amount_original_paid_mxn, 0.001);
    }

    public function test_sin_pagos_el_tipo_de_cambio_de_pago_es_cero(): void
    {
        $t = $this->rows()[2];

        $this->assertEqualsWithDelta(0, (float) $t->paid_exchange_value, 0.001);
        $this->assertEqualsWithDelta(0, (float) $t->total_amount_paid_tc, 0.001);
    }

    public function test_sin_conversion_deja_los_montos_en_su_divisa(): void
    {
        $rows = $this->rows(['noExchange' => true]);

        $this->assertEqualsWithDelta(282, (float) $rows[1]->total_amount, 0.001);
        $this->assertEqualsWithDelta(-126, (float) $rows[2]->total_amount, 0.001);
        $this->assertEqualsWithDelta(1120, (float) $rows[4]->total_amount, 0.001, 'sin TC de por medio ya no hay NULL');
    }

    public function test_sin_negativos_los_costos_dejan_de_restar(): void
    {
        $t = $this->rows(['noNegative' => true])[2];

        $this->assertEqualsWithDelta(2268, (float) $t->total_amount, 0.001);
    }

    public function test_en_modo_pago_el_costo_es_positivo_y_la_nota_de_credito_resta(): void
    {
        $rows = $this->rows(['paymentMode' => true]);

        // La condición cambia a "todo lo que no sea nota de crédito".
        $this->assertEqualsWithDelta(2268, (float) $rows[2]->total_amount, 0.001, 'el costo pasa a positivo');
        $this->assertEqualsWithDelta(58, (float) $rows[3]->total_amount, 0.001, 'la nota de crédito invierte');
    }

    public function test_las_dos_rutas_de_ejecucion_dan_el_mismo_resultado(): void
    {
        // Paginar usa la ruta en dos fases (IDs y luego agregados); `get()` sin tope
        // usa la consulta única. Deben coincidir columna por columna.
        $filters = TransactionFilters::make([]);

        $single = TransactionQuery::make($filters)->get()->keyBy('transc_id');
        $paged = TransactionQuery::make($filters)->paginate(10, 1)->keyBy('transc_id');

        $this->assertSame($single->keys()->all(), $paged->keys()->all());

        foreach ($single as $id => $row) {
            $this->assertEquals((array) $row, (array) $paged[$id], "La transacción {$id} difiere entre las dos rutas.");
        }
    }

    public function test_los_filtros_de_estado_de_pago_clasifican_bien(): void
    {
        // T1 está cobrada a medias; T2, T3 y T4 no tienen ningún pago.
        $this->assertSame([2, 3, 4], $this->ids(['paid' => 0]), 'Unpaid');
        $this->assertSame([1], $this->ids(['paid' => 2]), 'Partial');
        $this->assertSame([], $this->ids(['paid' => 1]), 'Paid');
    }

    public function test_filtra_por_tipo_booking_y_texto(): void
    {
        $this->assertSame([1, 4], $this->ids(['type' => [0]]));
        $this->assertSame([2, 3], $this->ids(['type' => [1, 2]]));
        $this->assertSame([1], $this->ids(['tran_number' => 'F-1']));
        $this->assertSame([1, 2, 3, 4], $this->ids(['booking_number' => 'BK']));
        $this->assertSame([1, 4], $this->ids(['appliedTo' => 'CLIENTE']));
        $this->assertSame([2, 3], $this->ids(['appliedTo' => 'PROVEEDOR']));
        $this->assertSame([1], $this->ids(['dates' => '01/01/2025 - 31/01/2025']));
    }

    public function test_el_cliente_solo_trae_facturas_y_el_proveedor_solo_costos(): void
    {
        $this->assertSame([1, 4], $this->ids(['customer' => 1]));
        $this->assertSame([2, 3], $this->ids(['vendor' => 1]));
    }

    public function test_el_listado_viene_ordenado_del_mas_reciente_al_mas_viejo(): void
    {
        $this->assertSame([4, 3, 2, 1], array_keys($this->rows()));
        $this->assertSame([1, 2, 3, 4], array_keys($this->rows(['direction' => 'asc'])));
    }

    public function test_los_totales_suman_todo_el_conjunto_filtrado(): void
    {
        $totals = TransactionQuery::make(TransactionFilters::make(['type' => [0]]))->totals(['amount_original']);

        // 250 de la primera factura + 1000 de la segunda.
        $this->assertEqualsWithDelta(1250, $totals['amount_original'], 0.001);
    }
}
