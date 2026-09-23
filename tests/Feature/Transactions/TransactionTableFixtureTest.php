<?php

namespace Tests\Feature\Transactions;

use App\Livewire\Transactions\TransactionDetail;
use App\Livewire\Transactions\TransactionTable;
use App\Models\User;
use App\Queries\ProfitByBooking;
use App\Queries\TransactionFilters;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Reglas del listado que se ven mejor con datos diminutos hechos a mano que
 * con la base real: la pantalla de una cotización, la casilla de selección y
 * el tipo de documento. Complementa a `TransactionTableTest`, que va contra
 * la base local por el volumen.
 */
class TransactionTableFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        $this->seedFixture();
        $this->actingAs($this->admin());
    }

    private function seedFixture(): void
    {
        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN']]);
        DB::table('exchange')->insert([['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1]]);
        DB::table('charge_type')->insert([['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0.16, 'tax_retention' => 0, 'non_deductible' => 0]]);
        DB::table('company')->insert(['company_id' => 1, 'name' => 'FTM', 'rfc' => 'AAA010101AAA']);
        DB::table('client')->insert(['client_id' => 1, 'fullName' => 'Cliente Uno']);
        DB::table('provider')->insert(['provider_id' => 1, 'fullName' => 'Proveedor Uno']);

        DB::table('booking')->insert([
            ['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10],
            // Una cotización: el motor la filtra por `booking.mode` = 9.
            ['booking_id' => 9, 'booking_number' => 'COT-9', 'client' => 1, 'mode' => 9],
        ]);
        // Cerrado: sin altas ni timbrado.
        DB::table('booking')->insert(['booking_id' => 2, 'booking_number' => 'BK-2', 'client' => 1, 'mode' => 10, 'locked' => 1]);

        $base = [
            'account' => 1, 'company_id' => 1, 'tran_date' => '2026-01-15', 'invoice_type' => 1,
            'cancelled' => 0, 'pdf_attach' => '', 'customer' => null, 'vendor' => null,
            'request_id' => null, 'payment_request' => 0,
        ];

        DB::table('transaction')->insert([
            ['transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'tran_number' => 'F-1'] + $base,
            // Saldada: se cobra completa abajo.
            ['transc_id' => 2, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'tran_number' => 'F-2'] + $base,
            ['transc_id' => 3, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'tran_number' => 'F-3', 'cancelled' => 1] + $base,
            // Pedido en la solicitud PR-2 y pagado en parte.
            ['transc_id' => 4, 'booking' => 1, 'tran_type' => 1, 'vendor' => 1, 'tran_number' => 'B-1', 'request_id' => 2, 'payment_request' => 1] + $base,
            ['transc_id' => 5, 'booking' => 1, 'tran_type' => 2, 'vendor' => 1, 'tran_number' => 'CB-1'] + $base,
            ['transc_id' => 6, 'booking' => 9, 'tran_type' => 0, 'customer' => 1, 'tran_number' => 'F-9'] + $base,
            ['transc_id' => 7, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'tran_number' => 'HIST-1', 'invoice_type' => 2] + $base,
            ['transc_id' => 8, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'tran_number' => 'NC-1', 'invoice_type' => 3] + $base,
            ['transc_id' => 9, 'booking' => 2, 'tran_type' => 0, 'customer' => 1, 'tran_number' => 'F-90'] + $base,
        ]);

        foreach ([1 => 1000, 2 => 500, 3 => 100, 4 => 200, 5 => 50, 6 => 300, 7 => 10, 8 => 20, 9 => 40] as $transaccion => $precio) {
            DB::table('charge')->insert([
                'charge_id' => $transaccion, 'transaction' => $transaccion, 'type' => 1, 'quantity' => 1, 'price' => $precio,
            ]);
        }

        // F-2 cobrada completa: 500 + 16 % = 580.
        DB::table('payment_request')->insert([
            'request_id' => 1, 'number' => 'PR-1', 'amount' => 580, 'paid' => 1,
            'client_id' => 1, 'currency_id' => 1, 'type' => 1, 'date' => '2026-01-15',
        ]);
        DB::table('payments_by_transaction')->insert(['request_id' => 1, 'transc_id' => 2, 'amount' => 580, 'paid' => 1]);

        // B-1 (200 + 16 % = 232) pagado en parte: 100.
        DB::table('bank')->insert(['bank_id' => 1, 'bank_name' => 'Banco Uno']);
        DB::table('payment_request')->insert([
            'request_id' => 2, 'number' => 'PR-2', 'amount' => 100, 'paid' => 0, 'bank_id' => 1,
            'provider_id' => 1, 'currency_id' => 1, 'type' => 2, 'date' => '2026-01-20',
        ]);
        DB::table('payments_by_transaction')->insert(['request_id' => 2, 'transc_id' => 4, 'amount' => 100, 'paid' => 0]);
    }

    private function admin(): User
    {
        $usuario = new User;
        $usuario->usr_id = 7;
        $usuario->role = User::ROLE_ADMIN;

        return $usuario;
    }

    private function pantalla(string $screen, ?int $booking = null): Testable
    {
        return Livewire::test(TransactionTable::class, ['screen' => $screen, 'booking' => $booking]);
    }

    /** @return int[] */
    private function tipos(Testable $pantalla): array
    {
        return collect($pantalla->viewData('rows')->items())->map(fn ($fila) => (int) $fila->tran_type)->unique()->values()->all();
    }

    // ------------------------------------------------------- Cotizaciones

    /**
     * El motor filtra por `booking.mode`, y la pantalla del booking no le decía
     * que era una cotización (modo 9): salía vacía, y su profit también.
     */
    public function test_las_transacciones_de_una_cotizacion_aparecen_en_su_pantalla(): void
    {
        $pantalla = $this->pantalla('booking', 9);

        $this->assertSame([6], collect($pantalla->viewData('rows')->items())->pluck('transc_id')->map(fn ($id) => (int) $id)->all());
        $this->assertEqualsWithDelta(300, $pantalla->viewData('bookingProfit')['inv_doc'], 0.01, 'El profit de la cotización también salía vacío.');
    }

    public function test_la_descarga_de_una_cotizacion_tampoco_sale_vacia(): void
    {
        $csv = $this->get(route('transactions.export', ['screen' => 'booking', 'booking' => 9]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('F-9', $csv);
    }

    // ------------------------------------------------ Casilla de selección

    public function test_una_transaccion_con_saldo_se_puede_marcar(): void
    {
        $pantalla = $this->pantalla('invoice');
        $fila = collect($pantalla->viewData('rows')->items())->firstWhere('tran_number', 'F-1');

        $this->assertNull($pantalla->instance()->unselectableReason($fila));
    }

    /** El original apagaba la casilla de los documentos con importe y sin saldo. */
    /**
     * En Costos, donde lo marcado va a una solicitud de pago, una transacción
     * saldada no se marca. En Facturas sí: ahí se timbra y se mandan
     * documentos, y eso no depende de si ya se cobró.
     */
    public function test_una_transaccion_saldada_no_se_puede_marcar_en_costos(): void
    {
        $pantalla = $this->pantalla('bill');
        $saldada = (object) ['transc_id' => 2, 'cancelled' => 0, 'left_to_pay' => 0, 'amount_original' => 1160];

        $this->assertNotNull($pantalla->instance()->unselectableReason($saldada));
    }

    public function test_una_transaccion_saldada_si_se_marca_en_facturas(): void
    {
        $pantalla = $this->pantalla('invoice');
        $fila = collect($pantalla->viewData('rows')->items())->firstWhere('tran_number', 'F-2');

        $this->assertNull($pantalla->instance()->unselectableReason($fila));
    }

    public function test_una_transaccion_cancelada_no_se_puede_marcar(): void
    {
        $pantalla = $this->pantalla('invoice')->set('showCancelled', '1');
        $fila = collect($pantalla->viewData('rows')->items())->firstWhere('tran_number', 'F-3');

        $this->assertNotNull($pantalla->instance()->unselectableReason($fila));
    }

    // ------------------------------------------------- Tipo de documento

    public function test_el_listado_dice_el_tipo_de_cada_documento(): void
    {
        $this->pantalla('all')
            ->assertSee(__('Nota de crédito prov.'))
            ->assertSee(__('Nota de crédito cliente'))
            ->assertSee(__('Histórica'))
            ->assertSee(__('Costo'));
    }

    public function test_el_filtro_de_tipo_acota_el_listado(): void
    {
        $pantalla = $this->pantalla('all')->set('docType', 'nota-credito');

        $this->assertSame([2], $this->tipos($pantalla));
        $this->assertSame(1, $pantalla->viewData('rows')->total());
    }

    public function test_el_filtro_de_tipo_distingue_las_facturas_por_su_tipo_de_factura(): void
    {
        $pantalla = $this->pantalla('invoice')->set('docType', 'historica');

        $this->assertSame(['HIST-1'], collect($pantalla->viewData('rows')->items())->pluck('tran_number')->all());
    }

    /** El filtro solo estrecha: en Facturas, pedir «costo» no mete costos. */
    public function test_el_filtro_de_tipo_no_abre_la_pantalla_a_otros_documentos(): void
    {
        $pantalla = $this->pantalla('invoice')->set('docType', 'costo');

        $this->assertSame([0], $this->tipos($pantalla));
    }

    public function test_cada_pantalla_ofrece_solo_sus_tipos(): void
    {
        $this->assertSame(['factura', 'historica', 'nota-credito-cliente'], array_keys($this->pantalla('invoice')->instance()->typeOptions()));
        $this->assertSame(['costo', 'nota-credito'], array_keys($this->pantalla('bill')->instance()->typeOptions()));
        $this->assertCount(5, $this->pantalla('all')->instance()->typeOptions());
    }

    public function test_la_descarga_lleva_el_tipo_y_respeta_su_filtro(): void
    {
        $csv = $this->get(route('transactions.export', ['screen' => 'all', 'tipo' => 'nota-credito']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(__('Tipo'), $csv);
        $this->assertStringContainsString('CB-1', $csv);
        $this->assertStringNotContainsString(',B-1,', $csv);
    }

    public function test_la_pantalla_del_booking_ofrece_la_nota_de_credito_de_proveedor(): void
    {
        $this->pantalla('booking', 1)
            ->assertSee(__('Nueva nota de crédito'))
            ->assertSeeHtml('tipo=nota-credito');
    }

    // ------------------------------------------- Columnas de cada listado

    public function test_costos_trae_documentos_solicitud_total_natural_y_saldo(): void
    {
        $this->pantalla('bill')
            ->assertSee('Non Dec')
            ->assertSee(__('Solicitud'))
            ->assertSee(__('Total natural'))
            ->assertSee(__('Saldo'))
            ->assertSeeHtml(route('payments.requests.show', 2))
            ->assertSee('PR-2')
            ->assertSee('132.00');
    }

    /**
     * Costos enseña la utilidad del booking al que pertenece cada costo: un
     * costo por sí solo no tiene utilidad, y sin esa cifra hay que salir de la
     * pantalla para saber si el booking deja dinero.
     */
    public function test_costos_trae_la_utilidad_del_booking(): void
    {
        $this->pantalla('bill')->assertSee(__('Utilidad del booking'));
    }

    public function test_la_utilidad_de_costos_es_facturas_menos_costos_del_booking(): void
    {
        $utilidades = $this->pantalla('bill')->viewData('bookingProfits');

        $esperada = (new ProfitByBooking(TransactionFilters::make([])))->summary();
        $delBooking = collect($esperada['rows'])->firstWhere('booking_id', 1);

        $this->assertEqualsWithDelta($delBooking['profit_doc'], $utilidades[1]['profit_doc'], 0.01);
    }

    public function test_la_descarga_de_costos_lleva_la_utilidad(): void
    {
        $csv = $this->get(route('transactions.export', ['screen' => 'bill']))->assertOk()->streamedContent();

        $this->assertStringContainsString(__('Utilidad del booking'), $csv);
    }

    public function test_facturas_no_trae_la_utilidad_del_booking(): void
    {
        $this->pantalla('invoice')->assertDontSee(__('Utilidad del booking'));
    }

    public function test_facturas_trae_el_pagado_a_tipo_de_cambio_de_pago(): void
    {
        $this->pantalla('invoice')
            ->assertSee('Non Dec')
            ->assertSee(__('Pagado (TC de pago)'))
            ->assertDontSee(__('Total natural'));
    }

    /** El pie de totales sigue a las columnas de cada pantalla, sin desalinearse. */
    public function test_el_pie_de_totales_lleva_una_celda_por_columna(): void
    {
        $html = $this->pantalla('bill')->set('showTotals', true)->html();

        preg_match('/<tfoot.*?<\/tfoot>/su', $html, $pie);
        preg_match_all('/<th\b/u', $html, $cabeceras);

        $this->assertSame(
            count($cabeceras[0]),
            substr_count($pie[0], '<td') - 1 + 8,
            'El pie debe cubrir todas las columnas: una celda que abarca 8 y una por cada columna restante.',
        );
    }

    public function test_la_descarga_de_costos_lleva_sus_columnas(): void
    {
        $csv = $this->get(route('transactions.export', ['screen' => 'bill']))->assertOk()->streamedContent();

        $this->assertStringContainsString('Non Dec', $csv);
        $this->assertStringContainsString(__('Solicitud'), $csv);
        $this->assertStringContainsString('PR-2', $csv);
        $this->assertStringContainsString(__('Saldo'), $csv);
    }

    // ----------------------------------------------- Rango por omisión

    public function test_ver_todos_los_anios_quita_el_rango_de_un_clic(): void
    {
        $pantalla = $this->pantalla('invoice');

        $this->assertNotSame('', $pantalla->get('dates'), 'El listado arranca acotado al año en curso.');

        $pantalla->assertSee(__('Ver todos los años'))
            ->call('showAllYears')
            ->assertSet('dates', '')
            ->assertSee(__('todos los años'));
    }

    public function test_limpiar_filtros_deja_la_pantalla_sin_rango(): void
    {
        $this->pantalla('invoice')->call('clearFilters')->assertSet('dates', '');
    }

    // ------------------------------------------- Pantalla de un booking

    public function test_en_un_booking_real_se_marca_timbra_y_solicita_pago(): void
    {
        $pantalla = $this->pantalla('booking', 1);

        $this->assertTrue($pantalla->instance()->allowsSelection());
        $this->assertTrue($pantalla->instance()->allowsStamping());

        $pantalla->assertSee(__('Timbrar seleccionadas'))->assertSee(__('Pagar'));
    }

    public function test_en_una_cotizacion_no_se_marca_nada(): void
    {
        $this->assertFalse($this->pantalla('booking', 9)->instance()->allowsSelection());
    }

    public function test_un_booking_cerrado_no_ofrece_altas_ni_timbrado(): void
    {
        $pantalla = $this->pantalla('booking', 2);

        $this->assertFalse($pantalla->instance()->allowsStamping());

        $pantalla->assertSee(__('Booking cerrado'))
            ->assertDontSee(__('Nueva factura'))
            ->assertDontSee(__('Timbrar seleccionadas'));
    }

    public function test_no_se_mezclan_facturas_y_costos_en_una_solicitud(): void
    {
        $this->pantalla('booking', 1)
            ->set('selected', ['1', '4'])
            ->call('createPaymentRequest')
            ->assertHasErrors('selected');
    }

    // ------------------------------------------------ Solicitudes de pago

    public function test_la_etiqueta_de_estado_lleva_a_las_solicitudes_del_documento(): void
    {
        $this->pantalla('bill')->assertSeeHtml(route('transactions.show', 4).'#solicitudes');
    }

    public function test_el_detalle_lista_las_solicitudes_que_pagan_la_transaccion(): void
    {
        Livewire::test(TransactionDetail::class, ['transaction' => 4])
            ->assertSeeHtml('id="solicitudes"')
            ->assertSee('PR-2')
            ->assertSee('Banco Uno')
            ->assertSee('20/01/2026')
            ->assertSee('100.00')
            ->assertSeeHtml(route('payments.requests.show', 2));
    }

    public function test_el_detalle_avisa_cuando_ninguna_solicitud_incluye_la_transaccion(): void
    {
        Livewire::test(TransactionDetail::class, ['transaction' => 1])
            ->assertSee(__('Ninguna solicitud de pago incluye esta transacción.'));
    }
}
