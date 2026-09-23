<?php

namespace Tests\Feature\Cfdi;

use App\Livewire\Transactions\TransactionDetail;
use App\Livewire\Transactions\TransactionTable;
use App\Models\Core\Transaction;
use App\Models\User;
use App\Queries\TransactionFilters;
use App\Support\Cfdi\PacClient;
use App\Support\Cfdi\SatStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\Support\FakePacClient;
use Tests\Support\FakeSatStatus;
use Tests\Support\InvoiceFixture;
use Tests\TestCase;

/**
 * Timbrado y cancelación de facturas.
 *
 * **Nunca se habla con el PAC**: se sustituye por un doble que guarda lo que se
 * le mandó. Un timbrado de prueba contra el PAC real consumiría folios, y contra
 * el de producción emitiría documentos fiscales de verdad.
 */
class StampingTest extends TestCase
{
    private FakePacClient $pac;

    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        Storage::fake('documentos');
        Http::preventStrayRequests();

        $this->pac = new FakePacClient;
        $this->app->instance(PacClient::class, $this->pac);
        $this->app->instance(SatStatus::class, new FakeSatStatus);

        InvoiceFixture::seed();
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        $usuario = new User;
        $usuario->usr_id = 7;
        $usuario->role = $rol;

        return $usuario;
    }

    private function detalle(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(TransactionDetail::class, ['transaction' => 1]);
    }

    public function test_timbrar_guarda_el_folio_y_los_archivos(): void
    {
        $this->detalle()->call('stamp')->assertHasNoErrors();

        $transaccion = Transaction::find(1);

        $this->assertSame($this->pac->uuid, $transaccion->seal);
        $this->assertSame($this->pac->uuid.'.xml', $transaccion->xml_attach);
        $this->assertSame($this->pac->uuid.'.pdf', $transaccion->pdf_attach);

        // En la MISMA carpeta que lee el sistema viejo.
        Storage::disk('documentos')->assertExists("transactions/1/pdf/{$this->pac->uuid}.xml");
        Storage::disk('documentos')->assertExists("transactions/1/pdf/{$this->pac->uuid}.pdf");
    }

    /** El layout que viaja al PAC es el que arma el generador, con sus totales. */
    public function test_el_layout_que_se_manda_lleva_los_totales_correctos(): void
    {
        $this->detalle()->call('stamp')->assertHasNoErrors();

        $layout = $this->pac->layoutRecibido;

        // 2 × 1 000 = 2 000 de subtotal, 16 % = 320, total 2 320.
        $this->assertStringContainsString("SubTotal=2000.00\n", $layout);
        $this->assertStringContainsString("Total=2320.00\n", $layout);
        $this->assertStringContainsString('Rfc= XAXX010101000', $layout, 'El emisor debe ser la compañía de la transacción.');
        $this->assertStringContainsString('Rfc=AAA010101AAA', $layout, 'El receptor debe ser el cliente.');
    }

    /** Yii2 pegaba el decimal(11,4) de MySQL: un TC entero va como «17.0000», no «17». */
    public function test_el_tipo_de_cambio_viaja_con_cuatro_decimales(): void
    {
        DB::table('account')->insert([['account_id' => 2, 'account_name' => 'Dólares', 'default' => 0, 'prefix' => 'USD']]);
        DB::table('exchange')->insert([['exchange_id' => 2, 'exchange_value' => 17, 'date_exchange' => '2026-01-15', 'account' => 2]]);
        DB::table('transaction')->where('transc_id', 1)->update(['account' => 2]);

        $this->detalle()->call('stamp')->assertHasNoErrors();

        $this->assertStringContainsString("TipoCambio=17.0000\n", $this->pac->layoutRecibido);
    }

    public function test_una_factura_ya_timbrada_no_se_vuelve_a_timbrar(): void
    {
        $this->detalle()->call('stamp')->assertHasNoErrors();

        $this->detalle()->call('stamp')->assertForbidden();
    }

    public function test_un_costo_de_proveedor_no_se_timbra(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['tran_type' => 1, 'customer' => null, 'vendor' => 1]);
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Proveedor Uno']]);

        $this->detalle()->call('stamp')->assertForbidden();

        $this->assertNull(Transaction::find(1)->seal);
    }

    /** Si el PAC rechaza, la factura tiene que quedar exactamente como estaba. */
    public function test_si_el_pac_rechaza_no_se_toca_la_factura(): void
    {
        $this->app->instance(PacClient::class, new FakePacClient(falla: 'CFDI40110: el total no cuadra'));

        $this->detalle()->call('stamp')->assertHasErrors('cfdi');

        $transaccion = Transaction::find(1);

        $this->assertNull($transaccion->seal);
        $this->assertSame('', $transaccion->pdf_attach);
        Storage::disk('documentos')->assertDirectoryEmpty('transactions');
    }

    public function test_quien_no_es_administrador_no_timbra(): void
    {
        $this->detalle(User::ROLE_USER)->call('stamp')->assertForbidden();

        $this->assertNull(Transaction::find(1)->seal);
    }

    // ---------------------------------------------------- Compañía emisora

    /**
     * Sin compañía el layout caía al RFC de la CUENTA del PAC y se timbraba a
     * nombre equivocado. El original abortaba con `getEmisorError()`; aquí igual,
     * y sin llegar al PAC.
     */
    public function test_sin_compania_emisora_no_se_timbra(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['company_id' => null]);

        $this->detalle()->call('stamp')->assertHasErrors('cfdi');

        $this->assertNull(Transaction::find(1)->seal);
        $this->assertNull($this->pac->layoutRecibido, 'No debió llegar al PAC.');
    }

    public function test_con_datos_fiscales_incompletos_no_se_timbra(): void
    {
        DB::table('company')->where('company_id', 1)->update(['postal_code' => null, 'regimen_fiscal' => '']);

        $errores = $this->detalle()->call('stamp')->assertHasErrors('cfdi')->errors();

        $this->assertStringContainsString('Régimen fiscal', $errores->first('cfdi'));
        $this->assertStringContainsString('C.P.', $errores->first('cfdi'));
        $this->assertNull($this->pac->layoutRecibido, 'No debió llegar al PAC.');
    }

    public function test_una_compania_inexistente_tampoco_timbra(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['company_id' => 99]);

        $this->detalle()->call('stamp')->assertHasErrors('cfdi');

        $this->assertNull($this->pac->layoutRecibido);
    }

    /** El aviso se ve ANTES de pulsar Timbrar, como el `fiscalWarning` del original. */
    public function test_el_detalle_avisa_antes_de_timbrar_si_falta_la_compania(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['company_id' => null]);

        $this->detalle()->assertSee('no tiene compañía emisora');
    }

    public function test_el_detalle_no_avisa_cuando_la_compania_esta_completa(): void
    {
        $this->detalle()->assertDontSee('datos fiscales');
    }

    // ---------------------------------------------------------- Cancelación

    public function test_cancelar_usa_el_rfc_con_el_que_se_timbro(): void
    {
        $this->detalle()->call('stamp')->assertHasNoErrors();

        $this->detalle()->call('startCancel')->set('cancelReason', '02')->call('cancelStamp')->assertHasNoErrors();

        $cancelacion = $this->pac->cancelaciones[0] ?? null;

        $this->assertNotNull($cancelacion, 'No se pidió la cancelación al PAC.');
        $this->assertSame($this->pac->uuid, $cancelacion['uuid']);
        // Sale del XML guardado, no de la configuración ni de la cuenta del PAC.
        $this->assertSame('XAXX010101000', $cancelacion['rfcEmisor']);
    }

    public function test_el_motivo_01_exige_el_folio_que_sustituye(): void
    {
        $this->detalle()->call('stamp')->assertHasNoErrors();

        $this->detalle()
            ->call('startCancel')
            ->set('cancelReason', '01')
            ->call('cancelStamp')
            ->assertHasErrors('cfdi');

        $this->assertSame(0, (int) Transaction::find(1)->cancelled);
    }

    public function test_una_factura_sin_timbrar_no_se_cancela(): void
    {
        $this->detalle()->call('startCancel')->assertForbidden();
    }

    public function test_con_el_motivo_01_viaja_el_folio_que_sustituye(): void
    {
        $this->detalle()->call('stamp')->assertHasNoErrors();

        $this->detalle()
            ->call('startCancel')
            ->set('cancelReason', '01')
            ->set('replacementUuid', 'UUID-NUEVO')
            ->call('cancelStamp')
            ->assertHasNoErrors();

        $this->assertSame('UUID-NUEVO', $this->pac->cancelaciones[0]['sustituye']);
    }

    /** El SAT solo admite folio de sustitución con el 01; con otro motivo se descarta aunque esté capturado. */
    public function test_el_folio_de_sustitucion_no_viaja_con_otros_motivos(): void
    {
        $this->detalle()->call('stamp')->assertHasNoErrors();

        $this->detalle()
            ->call('startCancel')
            ->set('cancelReason', '02')
            ->set('replacementUuid', 'UUID-QUE-SOBRA')
            ->call('cancelStamp')
            ->assertHasNoErrors();

        $this->assertNull($this->pac->cancelaciones[0]['sustituye']);
        $this->assertNull(Transaction::find(1)->new_seal);
    }

    /**
     * Timbrado en lote desde el listado (el «Seal» del sistema viejo): timbra las
     * que se pueden e informa una por una las que no.
     */
    public function test_timbrado_en_lote_desde_el_listado(): void
    {
        // Una segunda factura YA timbrada: el lote la omite y lo dice, sin
        // tocarle el sello y sin ensuciar la lista de errores.
        DB::table('transaction')->insert([
            'transc_id' => 2, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'company_id' => 1,
            'account' => 1, 'tran_number' => 'F-2', 'tran_date' => '2026-01-15', 'invoice_type' => 1,
            'seal' => 'YA-TIMBRADA',
        ]);
        DB::table('charge')->insert([
            'charge_id' => 2, 'transaction' => 2, 'type' => 1, 'quantity' => 1, 'price' => 500,
            'description' => 'Flete',
        ]);

        $this->actingAs($this->usuario());

        $resultado = Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->set('selected', [1, 2])
            ->call('stampSelected')
            ->assertHasNoErrors()
            ->get('stampResult');

        $this->assertSame(1, $resultado['done']);
        $this->assertSame([], $resultado['errors']);
        $this->assertSame(1, $resultado['skipped']);
        $this->assertSame($this->pac->uuid, Transaction::find(1)->seal);
        $this->assertSame('YA-TIMBRADA', Transaction::find(2)->seal);
    }

    public function test_un_no_administrador_no_timbra_en_lote(): void
    {
        $this->actingAs($this->usuario(User::ROLE_USER));

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->set('selected', [1])
            ->call('stampSelected')
            ->assertForbidden();

        $this->assertNull(Transaction::find(1)->seal);
    }

    // ------------------------------------------- Marcar todas y filtros

    /**
     * Timbrar en lote empieza por marcar, y marcar de una en una no es marcar.
     * La rejilla del sistema original traía la casilla en la cabecera.
     */
    public function test_la_cabecera_marca_todas_las_de_la_pagina(): void
    {
        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);
        $marcables = collect($pantalla->viewData('rows')->items())
            ->reject(fn ($fila) => $pantalla->instance()->unselectableReason($fila) !== null)
            ->pluck('transc_id')
            ->map(intval(...))
            ->all();

        $pantalla->call('toggleAll', $pantalla->viewData('rows')->items());

        $this->assertSame($marcables, $pantalla->get('selected'));
    }

    /** Desde el navegador los renglones llegan como arreglos, no como objetos. */
    public function test_marcar_todas_acepta_los_renglones_como_llegan_del_navegador(): void
    {
        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);
        $filas = collect($pantalla->viewData('rows')->items())->map(fn ($fila) => (array) $fila)->all();

        $pantalla->call('toggleAll', $filas)->assertHasNoErrors();

        $this->assertNotSame([], $pantalla->get('selected'));
    }

    public function test_volver_a_pulsarla_desmarca_todas(): void
    {
        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);
        $filas = $pantalla->viewData('rows')->items();

        $pantalla->call('toggleAll', $filas)->call('toggleAll', $filas);

        $this->assertSame([], $pantalla->get('selected'));
    }

    /** Lo que falta por timbrar se encuentra con un filtro, no a ojo. */
    public function test_el_filtro_sin_timbrar_trae_las_que_no_tienen_sello(): void
    {
        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->set('cfdiEstado', TransactionFilters::CFDI_SIN_TIMBRAR);

        foreach ($pantalla->viewData('rows')->items() as $fila) {
            $this->assertEmpty($fila->seal);
        }
    }

    public function test_el_filtro_timbradas_solo_trae_las_selladas(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['seal' => '3ECE3E47-7242-44E9-B6DB-355091F891C2']);

        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->set('cfdiEstado', TransactionFilters::CFDI_TIMBRADA);

        $this->assertSame([1], collect($pantalla->viewData('rows')->items())->pluck('transc_id')->map(intval(...))->all());
    }

    /** Los archivos del CFDI se abren desde cualquier listado, no solo desde Costos. */
    public function test_el_listado_de_facturas_ofrece_el_pdf_y_el_xml(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update([
            'pdf_attach' => 'factura.pdf', 'xml_attach' => 'factura.xml',
        ]);

        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->assertSeeHtml(route('transactions.file', [1, 'pdf']))
            ->assertSeeHtml(route('transactions.file', [1, 'xml']));
    }

    /** «Marcar sin timbrar» deja fuera las que ya tienen sello. */
    public function test_marcar_sin_timbrar_solo_toma_las_que_faltan(): void
    {
        DB::table('transaction')->insert([
            'transc_id' => 2, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'company_id' => 1,
            'account' => 1, 'tran_number' => 'F-2', 'tran_date' => '2026-01-15', 'invoice_type' => 1,
            'seal' => 'YA-TIMBRADA',
        ]);

        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);
        $pantalla->call('selectUnstamped', $pantalla->viewData('rows')->items());

        $this->assertSame([1], $pantalla->get('selected'));
    }

    public function test_sin_facturas_pendientes_el_boton_lo_dice(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['seal' => 'YA-TIMBRADA']);

        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);
        $pantalla->call('selectUnstamped', $pantalla->viewData('rows')->items())
            ->assertHasErrors('selected');
    }

    /** Los selectores de filtro se aplican al cambiarlos, sin pulsar «Filtrar». */
    public function test_cambiar_un_selector_filtra_de_inmediato(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['seal' => 'YA-TIMBRADA']);

        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->set('cfdiEstado', TransactionFilters::CFDI_SIN_TIMBRAR);

        $this->assertSame([], collect($pantalla->viewData('rows')->items())->pluck('transc_id')->all());
        $this->assertStringContainsString('wire:model.live="cfdiEstado"', $pantalla->html());
    }

    /**
     * En Facturas una factura ya cobrada se puede marcar.
     *
     * La regla que apagaba la casilla es la de las solicitudes de pago (no
     * queda nada por cobrar), y en esta pantalla lo que se hace con lo marcado
     * es timbrar y mandar documentos. Con esa regla puesta, en producción
     * salían las cincuenta casillas de la página apagadas y no se podía timbrar
     * en lote.
     */
    public function test_en_facturas_una_saldada_si_se_puede_marcar(): void
    {
        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);
        $fila = collect($pantalla->viewData('rows')->items())->firstWhere('transc_id', 1);
        $fila->left_to_pay = 0;
        $fila->amount_original = 2320;

        $this->assertNull($pantalla->instance()->unselectableReason($fila));
    }

    /** En Costos sigue mandando la regla de cobranza: una saldada no se marca. */
    public function test_en_costos_una_saldada_no_se_marca(): void
    {
        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'bill']);
        $fila = (object) ['transc_id' => 9, 'cancelled' => 0, 'left_to_pay' => 0, 'amount_original' => 1000];

        $this->assertNotNull($pantalla->instance()->unselectableReason($fila));
    }

    /** Una cancelada no se marca en ninguna pantalla. */
    public function test_una_cancelada_nunca_se_marca(): void
    {
        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);
        $fila = (object) ['transc_id' => 9, 'cancelled' => 1, 'left_to_pay' => 100, 'amount_original' => 1000];

        $this->assertNotNull($pantalla->instance()->unselectableReason($fila));
    }

    /** Desde el listado se llega a cancelar una factura timbrada. */
    public function test_el_listado_ofrece_cancelar_una_factura_timbrada(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['seal' => 'YA-TIMBRADA']);

        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->assertSee(__('Cancelar'))
            ->assertSeeHtml(route('transactions.show', 1));
    }
}
