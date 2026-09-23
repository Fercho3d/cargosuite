<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\PaymentRequestDetail;
use App\Livewire\Payments\PaymentRequestForm;
use App\Livewire\Payments\PaymentRequestList;
use App\Livewire\Payments\PaymentsReport;
use App\Livewire\Transactions\TransactionTable;
use App\Models\Core\PaymentByTransaction;
use App\Models\Core\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Alta de solicitudes de pago y su ciclo de vida.
 *
 * Corre sobre el esquema de pruebas porque escribe. El escenario cabe en la
 * cabeza: un proveedor con dos costos en pesos y un tercero con uno en dólares,
 * para poder comprobar las dos reglas que impiden agruparlos.
 */
class PaymentRequestFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        $this->seedFixture();

        Http::preventStrayRequests();
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [['datos' => []]]]])]);
    }

    private function seedFixture(): void
    {
        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN'],
            ['account_id' => 2, 'account_name' => 'Dólares', 'default' => null, 'prefix' => 'USD'],
        ]);

        DB::table('exchange')->insert([
            ['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1],
            ['exchange_id' => 2, 'exchange_value' => 20, 'date_exchange' => '2026-01-15', 'account' => 2],
        ]);

        DB::table('bank')->insert([['bank_id' => 1, 'bank_name' => 'BBVA MXN', 'active' => 1]]);
        DB::table('booking')->insert([['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10]]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('provider')->insert([
            ['provider_id' => 1, 'fullName' => 'Proveedor Uno'],
            ['provider_id' => 2, 'fullName' => 'Proveedor Dos'],
        ]);
        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0, 'tax_retention' => 0, 'non_deductible' => 0],
        ]);

        // Dos costos del mismo proveedor en pesos, uno de otro proveedor y uno en dólares.
        $costos = [
            [1, 1, 1, 1000],
            [2, 1, 1, 500],
            [3, 2, 1, 700],
            [4, 1, 2, 300],
        ];

        foreach ($costos as [$id, $proveedor, $cuenta, $importe]) {
            DB::table('transaction')->insert([
                'transc_id' => $id, 'booking' => 1, 'tran_type' => 1, 'vendor' => $proveedor,
                'account' => $cuenta, 'tran_number' => "C-{$id}", 'tran_date' => '2026-01-15',
            ]);
            DB::table('charge')->insert([
                'charge_id' => $id, 'transaction' => $id, 'type' => 1, 'quantity' => 1, 'price' => $importe,
            ]);
        }
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        $usuario = new User;
        $usuario->usr_id = 7;
        $usuario->role = $rol;

        return $usuario;
    }

    /** @param  int[]  $ids */
    private function formulario(array $ids): Testable
    {
        $this->actingAs($this->usuario());

        return Livewire::withQueryParams(['ids' => implode(',', $ids)])->test(PaymentRequestForm::class);
    }

    public function test_agrupar_dos_costos_del_mismo_proveedor(): void
    {
        $this->formulario([1, 2])
            ->set('number', 'CHQ-100')
            ->set('date', '2026-01-20')
            ->set('bankId', '1')
            ->call('save')
            ->assertHasNoErrors();

        $solicitud = PaymentRequest::first();

        $this->assertSame(2, (int) $solicitud->type, 'Un costo genera una solicitud de pago a proveedor.');
        $this->assertSame(1, (int) $solicitud->provider_id);
        $this->assertSame(1500.0, (float) $solicitud->amount);
        $this->assertSame(2, PaymentByTransaction::where('request_id', $solicitud->request_id)->count());
    }

    /** Las columnas heredadas de `transaction` cuentan desde que se crea la solicitud, como en el original. */
    public function test_el_pago_queda_aplicado_a_cada_transaccion(): void
    {
        $this->formulario([1, 2])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1000.0, (float) DB::table('transaction')->where('transc_id', 1)->value('paid_amount'));
        $this->assertSame(500.0, (float) DB::table('transaction')->where('transc_id', 2)->value('paid_amount'));
    }

    // -------------------------------- Columnas heredadas de `transaction` (B5)

    /** @return array{paid_amount: float, paid: int, paid_at: ?string} */
    private function columnasHeredadas(int $transaccion): array
    {
        $fila = DB::table('transaction')->where('transc_id', $transaccion)->first(['paid_amount', 'paid', 'paid_at']);

        return ['paid_amount' => (float) $fila->paid_amount, 'paid' => (int) $fila->paid, 'paid_at' => $fila->paid_at];
    }

    /** Crear la solicitud ya salda la transacción (`payTran` del original), aunque siga pendiente. */
    public function test_crear_la_solicitud_salda_la_transaccion(): void
    {
        $this->solicitudCreada();

        $columnas = $this->columnasHeredadas(1);

        $this->assertSame([1000.0, 1, true], [$columnas['paid_amount'], $columnas['paid'], $columnas['paid_at'] !== null]);
    }

    /** Pagar no vuelve a acumular (el `payRequest` del original duplicaba) y conserva `paid_at`. */
    public function test_pagar_no_duplica_lo_aplicado(): void
    {
        $id = $this->solicitudCreada();
        $antes = $this->columnasHeredadas(1);

        $this->listado()->call('markPaid', $id);

        $this->assertSame($antes, $this->columnasHeredadas(1));
    }

    public function test_borrar_deja_la_transaccion_sin_pagar(): void
    {
        $id = $this->solicitudCreada();

        $this->listado(User::ROLE_SUPER_ADMIN)->call('delete', $id);

        $this->assertSame(['paid_amount' => 0.0, 'paid' => 0, 'paid_at' => null], $this->columnasHeredadas(1));
    }

    /** Aplicar una parte deja `paid = 2`, el «parcial» del original. */
    public function test_un_pago_parcial_queda_como_parcial(): void
    {
        $this->formulario([1])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.1', '400')
            ->call('save')->assertHasNoErrors();

        $columnas = $this->columnasHeredadas(1);

        $this->assertSame([400.0, 2], [$columnas['paid_amount'], $columnas['paid']]);
    }

    /** El reparto guardado en `payments`, como lo lee el original. */
    private function reparto(int $id): array
    {
        return array_map('floatval', json_decode(PaymentRequest::find($id)->payments, true));
    }

    /** El JSON del reparto (`payments`) sigue a las correcciones, como al crear. */
    public function test_corregir_y_quitar_actualizan_el_reparto(): void
    {
        $id = $this->solicitudCreada();

        $this->actingAs($this->usuario());

        $detalle = Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->set('amounts.1', '800')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([1 => 800.0, 2 => 500.0], $this->reparto($id));

        $detalle->call('removeTransaction', 2);

        $this->assertSame([1 => 800.0], $this->reparto($id));
    }

    public function test_no_se_agrupan_proveedores_distintos(): void
    {
        $this->formulario([1, 3])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')
            ->assertHasErrors('seleccion');

        $this->assertSame(0, PaymentRequest::count());
    }

    /**
     * Cada error se pinta UNA vez. La pantalla llevaba además el resumen de
     * `partials.validation-errors`, que repetía lo que ya sale bajo cada campo
     * y en cada renglón de la tabla.
     */
    public function test_el_error_no_sale_repetido(): void
    {
        $componente = $this->formulario([1, 4])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')
            ->assertHasErrors('seleccion');

        $this->assertSame(
            1,
            substr_count($componente->html(), 'No se pueden agrupar transacciones de distinta divisa'),
            'El mensaje de divisa distinta debería salir una sola vez.'
        );
    }

    public function test_no_se_agrupan_divisas_distintas(): void
    {
        $this->formulario([1, 4])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')
            ->assertHasErrors('seleccion');

        $this->assertSame(0, PaymentRequest::count());
    }

    /**
     * La misma regla, pero adelantada al listado: marcar transacciones que no se
     * pueden agrupar ni siquiera lleva a la pantalla de pago; el aviso sale ahí.
     */
    public function test_el_listado_no_deja_pasar_a_pago_proveedores_distintos(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'bill'])
            ->set('selected', [1, 3])
            ->call('createPaymentRequest')
            ->assertHasErrors('selected')
            ->assertNoRedirect();
    }

    public function test_el_listado_no_deja_pasar_a_pago_divisas_distintas(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'bill'])
            ->set('selected', [1, 4])
            ->call('createPaymentRequest')
            ->assertHasErrors('selected')
            ->assertNoRedirect();
    }

    public function test_el_listado_pasa_a_pago_cuando_se_pueden_agrupar(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'bill'])
            ->set('selected', [1, 2])
            ->call('createPaymentRequest')
            ->assertHasNoErrors()
            ->assertRedirect();
    }

    /** El switch «mostrar sumatoria» prende y apaga el pie de totales. */
    // ---------------------------------------------------- Vista de detalle

    private function solicitudCreada(): int
    {
        $this->formulario([1, 2])
            ->set('number', 'CHQ-DET')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')->assertHasNoErrors();

        return (int) PaymentRequest::first()->request_id;
    }

    public function test_el_detalle_muestra_la_solicitud_y_sus_transacciones(): void
    {
        $id = $this->solicitudCreada();

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->assertOk()
            ->assertSee(str_pad((string) $id, 4, '0', STR_PAD_LEFT))
            ->assertSee('C-1')
            ->assertSee(__('Volver'));
    }

    public function test_una_solicitud_inexistente_da_404(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestDetail::class, ['request' => 9999])->assertNotFound();
    }

    public function test_desde_el_detalle_se_marca_pagada(): void
    {
        $id = $this->solicitudCreada();

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->call('markPaid')
            ->assertHasNoErrors();

        $this->assertSame(1, (int) PaymentRequest::find($id)->paid);
    }

    public function test_borrar_desde_el_detalle_regresa_al_listado(): void
    {
        $id = $this->solicitudCreada();

        $this->actingAs($this->usuario(User::ROLE_SUPER_ADMIN));

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->call('delete')
            ->assertRedirect();

        $this->assertSame(0, PaymentRequest::count());
    }

    public function test_reabierta_se_corrigen_encabezado_e_importes(): void
    {
        $id = $this->solicitudCreada();
        PaymentRequest::whereKey($id)->update(['paid' => 1, 'opened' => 0]);

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->call('reopen')
            ->set('number', 'CHQ-CORREGIDO')
            ->set('amounts.1', '800')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['CHQ-CORREGIDO', 1300.0, 800.0],
            [PaymentRequest::find($id)->number, (float) PaymentRequest::find($id)->amount, (float) PaymentByTransaction::where('transc_id', 1)->value('amount')],
        );
    }

    /** Como el `beforeSave` de Yii2: el renglón firma quién lo creó y quién lo corrigió. */
    public function test_los_renglones_firman_alta_y_correccion(): void
    {
        $id = $this->solicitudCreada();

        $usuario = $this->usuario();
        $usuario->usr_id = 9;
        $this->actingAs($usuario);

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->set('amounts.1', '800')
            ->call('save')
            ->assertHasNoErrors();

        $renglon = PaymentByTransaction::where('transc_id', 1)->first();

        $this->assertSame(
            [7, true, 9, true],
            [(int) $renglon->created_by, $renglon->created_at !== null, (int) $renglon->modified_by, $renglon->modified_at !== null],
        );
    }

    public function test_reabierta_se_quita_una_transaccion(): void
    {
        $id = $this->solicitudCreada();

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])->call('removeTransaction', 2);

        $this->assertSame([1000.0, [1]], [
            (float) PaymentRequest::find($id)->amount,
            PaymentByTransaction::where('request_id', $id)->pluck('transc_id')->all(),
        ]);
    }

    /**
     * «Quitar» no guarda número, fecha ni banco: eso entra por «Guardar
     * cambios», que los valida. Antes se escribían tal cual, aunque estuvieran
     * vacíos.
     */
    public function test_quitar_no_guarda_un_encabezado_sin_validar(): void
    {
        $id = $this->solicitudCreada();

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->set('number', '')
            ->set('bankId', '')
            ->call('removeTransaction', 2);

        $this->assertSame(['CHQ-DET', 1], [PaymentRequest::find($id)->number, (int) PaymentRequest::find($id)->bank_id]);
    }

    /** Cambiar la fecha pide el tipo de cambio de ese día, como el `beforeSave` original. */
    public function test_cambiar_la_fecha_pide_el_tipo_de_cambio_de_ese_dia(): void
    {
        $id = $this->solicitudCreada();

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->set('date', '2026-01-21')
            ->call('save')
            ->assertHasNoErrors();

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), '2026-01-21/2026-01-21'));
    }

    /** Borrar es solo del super administrador, como en el original. */
    public function test_borrar_es_solo_para_el_super_administrador(): void
    {
        $id = $this->solicitudCreada();

        $this->listado()->call('delete', $id)->assertForbidden();
        $this->detalle($id)->call('delete')->assertForbidden();

        $this->assertSame(1, PaymentRequest::count());
    }

    public function test_no_se_corrige_mas_de_lo_que_vale_el_documento(): void
    {
        $id = $this->solicitudCreada();

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->set('amounts.1', '5000')
            ->call('save')
            ->assertHasErrors('amounts.1');
    }

    public function test_pagada_no_se_corrige(): void
    {
        $id = $this->solicitudCreada();
        PaymentRequest::whereKey($id)->update(['paid' => 1]);

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->call('save')
            ->assertStatus(422);
    }

    public function test_el_switch_muestra_y_oculta_la_sumatoria(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'bill'])
            ->assertSet('totals', null)
            ->set('showTotals', true)
            ->assertNotSet('totals', null)
            ->set('showTotals', false)
            ->assertSet('totals', null);
    }

    /** El switch se recuerda: si la cookie viene puesta, arranca encendido. */
    public function test_el_switch_recuerda_su_estado_por_cookie(): void
    {
        $this->actingAs($this->usuario());

        Livewire::withCookies(['mostrar_totales' => '1'])
            ->test(TransactionTable::class, ['screen' => 'bill'])
            ->assertSet('showTotals', true);
    }

    public function test_el_listado_de_solicitudes_muestra_la_sumatoria(): void
    {
        $this->solicitudCreada();

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestList::class)
            ->set('showTotals', true)
            ->assertSee(__('Total del filtro completo'));
    }

    /** El folio encuentra la solicitud aunque quede fuera del rango de fechas. */
    public function test_el_listado_filtra_por_folio(): void
    {
        $id = $this->solicitudCreada();

        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestList::class)
            ->set('dates', '01/01/2020 - 31/12/2020')
            ->set('folioId', str_pad((string) $id, 4, '0', STR_PAD_LEFT))
            ->assertViewHas('filas', fn ($filas) => $filas->pluck('request_id')->map(fn ($v) => (int) $v)->all() === [$id]);
    }

    public function test_ver_todas_las_solicitudes_quita_la_paginacion(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestList::class)
            ->call('verTodas')
            ->assertSet('perPage', 100000);
    }

    public function test_volver_del_detalle_regresa_al_listado_con_su_filtro(): void
    {
        $this->actingAs($this->usuario());

        $url = Livewire::test(PaymentRequestList::class)
            ->set('type', '2')
            ->instance()
            ->currentUrl();

        $this->assertStringStartsWith('/pagos/solicitudes?tipo=2', $url);
    }

    public function test_el_importe_se_puede_ajustar_renglon_por_renglon(): void
    {
        $this->formulario([1, 2])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.1', '400')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(900.0, (float) PaymentRequest::first()->amount);
        $this->assertSame(400.0, (float) PaymentByTransaction::where('transc_id', 1)->value('amount'));
    }

    /**
     * Porta `Transaction::validateAmountToPay()`: en Yii2 se comprobaba por AJAX
     * al teclear en la rejilla, sobre un campo virtual del modelo.
     */
    public function test_no_se_puede_aplicar_mas_de_lo_que_se_debe(): void
    {
        $this->formulario([1])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.1', '1160.01')
            ->call('save')
            ->assertHasErrors('amounts.1');

        $this->assertSame(0, PaymentRequest::count());
    }

    public function test_un_importe_en_cero_no_arma_solicitud(): void
    {
        $this->formulario([1])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.1', '0')
            ->call('save')
            ->assertHasErrors('amounts.1');

        $this->assertSame(0, PaymentRequest::count());
    }

    /**
     * Una transacción YA SALDADA no entra a una solicitud nueva, ni con importe
     * ni en cero.
     *
     * En Yii2 ni siquiera tenía casilla en la rejilla (`_transactions.php` la
     * apagaba con `left_to_pay == 0 && amount_original != 0`). Aquí llega por
     * la dirección, así que se rechaza con su motivo en el renglón. Antes esta
     * pantalla usaba el tope de la solicitud reabierta (el total del documento)
     * y la dejaba pasar con hasta 1,000: eso sobre-aplicaba.
     */
    public function test_una_transaccion_saldada_no_entra_a_una_solicitud_nueva(): void
    {
        $this->conCostoSaldado();

        $this->formulario([5])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.5', '1000')
            ->call('save')
            ->assertHasErrors('amounts.5');

        $this->assertSame(0, PaymentRequest::where('request_id', '>', 90)->count());
    }

    public function test_una_transaccion_saldada_se_rechaza_con_su_motivo(): void
    {
        $this->conCostoSaldado();

        $componente = $this->formulario([5])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')
            ->assertHasErrors('amounts.5');

        $this->assertStringContainsString(__('La transacción ya está saldada: no se puede volver a pedir su pago.'), $componente->html());
    }

    public function test_una_transaccion_cancelada_no_entra_a_una_solicitud_nueva(): void
    {
        DB::table('transaction')->where('transc_id', 2)->update(['cancelled' => 1]);

        $this->formulario([1, 2])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')
            ->assertHasErrors('amounts.2');

        $this->assertSame(0, PaymentRequest::count());
    }

    // ------------------------- Tope al crear: el saldo, no el total (A11)

    /** El costo 1 (1,000) con 600 pagados por OTRA solicitud: quedan 400. */
    private function conCostoParcial(): void
    {
        DB::table('payment_request')->insert([
            'request_id' => 90, 'type' => 2, 'provider_id' => 1, 'currency_id' => 1, 'amount' => 600, 'paid' => 1,
        ]);
        DB::table('payments_by_transaction')->insert([
            'request_id' => 90, 'transc_id' => 1, 'amount' => 600, 'paid' => 1,
        ]);
    }

    public function test_el_alta_propone_el_saldo(): void
    {
        $this->conCostoParcial();

        $this->formulario([1])->assertSet('amounts.1', '400');
    }

    /**
     * Porta la columna «To pay» del alta en Yii2, que validaba SIN `modeopen` y
     * por tanto contra el saldo: el viejo rechazaba más de 400 y el nuevo
     * aceptaba hasta 1,000.
     */
    public function test_el_alta_no_aplica_mas_del_saldo(): void
    {
        $this->conCostoParcial();

        $this->formulario([1])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.1', '400.01')
            ->call('save')
            ->assertHasErrors('amounts.1');

        $this->assertSame(0, PaymentRequest::where('request_id', '<>', 90)->count());
    }

    public function test_el_alta_aplica_hasta_el_saldo(): void
    {
        $this->conCostoParcial();

        $this->formulario([1])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.1', '400')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(400.0, (float) PaymentRequest::where('request_id', '<>', 90)->value('amount'));
    }

    /**
     * Reabierta: el tope es el saldo más lo aplicado en ESTA solicitud, no el
     * total del documento. Con 600 pagados por otra y 400 aquí, se puede volver
     * a repartir hasta 400, no hasta 1,000.
     */
    public function test_reabierta_no_aplica_lo_que_otra_solicitud_ya_pago(): void
    {
        $id = $this->solicitudSobreElCostoParcial();

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->set('amounts.1', '400.01')
            ->call('save')
            ->assertHasErrors('amounts.1');
    }

    public function test_reabierta_vuelve_a_repartir_lo_aplicado_aqui(): void
    {
        $id = $this->solicitudSobreElCostoParcial();

        Livewire::test(PaymentRequestDetail::class, ['request' => $id])
            ->set('amounts.1', '300')
            ->call('save')
            ->assertHasNoErrors()
            ->set('amounts.1', '400')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(400.0, (float) PaymentRequest::find($id)->amount);
    }

    /** Una solicitud propia con los 400 que quedaban del costo 1. */
    private function solicitudSobreElCostoParcial(): int
    {
        $this->conCostoParcial();

        $this->formulario([1])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')
            ->assertHasNoErrors();

        return (int) PaymentRequest::where('request_id', '<>', 90)->value('request_id');
    }

    /** Un costo de 1,000 cobrado por completo por otra solicitud. */
    private function conCostoSaldado(): void
    {
        DB::table('transaction')->insert([
            'transc_id' => 5, 'booking' => 1, 'tran_type' => 1, 'vendor' => 1,
            'account' => 1, 'tran_number' => 'C-5', 'tran_date' => '2026-01-15',
        ]);
        DB::table('charge')->insert([
            'charge_id' => 5, 'transaction' => 5, 'type' => 1, 'quantity' => 1, 'price' => 1000,
        ]);
        DB::table('payment_request')->insert([
            'request_id' => 90, 'type' => 2, 'provider_id' => 1, 'amount' => 1000, 'paid' => 1,
        ]);
        DB::table('payments_by_transaction')->insert([
            'request_id' => 90, 'transc_id' => 5, 'amount' => 1000, 'paid' => 1,
        ]);
    }

    /** Una nota de crédito resta: su importe va en negativo y no pasa del saldo. */
    public function test_una_nota_de_credito_exige_importe_negativo(): void
    {
        DB::table('transaction')->insert([
            'transc_id' => 9, 'booking' => 1, 'tran_type' => 2, 'vendor' => 1,
            'account' => 1, 'tran_number' => 'NC-9', 'tran_date' => '2026-01-15',
        ]);
        DB::table('charge')->insert([
            'charge_id' => 9, 'transaction' => 9, 'type' => 1, 'quantity' => 1, 'price' => 200,
        ]);

        $this->formulario([9])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.9', '200')
            ->call('save')
            ->assertHasErrors('amounts.9');

        $this->formulario([9])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.9', '-1000')
            ->call('save')
            ->assertHasErrors('amounts.9');

        $this->assertSame(0, PaymentRequest::count());
    }

    // ------------------------------- Agregar a una solicitud reabierta (A12)

    /**
     * Solicitud con el costo 1 (proveedor 1, MXN). Quedan libres el 2 (mismo
     * proveedor y divisa), el 3 (otro proveedor) y el 4 (dólares).
     */
    private function solicitudConUnCosto(): int
    {
        $this->formulario([1])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')
            ->assertHasNoErrors();

        return (int) PaymentRequest::first()->request_id;
    }

    private function detalle(int $id, int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(PaymentRequestDetail::class, ['request' => $id]);
    }

    /** Es el selector de «Add» en Yii2: mismo tipo, mismo tercero, misma divisa, con saldo. */
    public function test_el_panel_solo_ofrece_las_del_mismo_proveedor_y_divisa(): void
    {
        $id = $this->solicitudConUnCosto();

        $this->detalle($id)
            ->call('toggleAdd')
            ->assertViewHas('candidatas', fn ($c) => $c->pluck('transc_id')->map(fn ($v) => (int) $v)->all() === [2]);
    }

    public function test_el_panel_busca_por_numero_o_booking(): void
    {
        $id = $this->solicitudConUnCosto();

        $this->detalle($id)
            ->call('toggleAdd')
            ->set('addSearch', 'c-2')
            ->assertViewHas('candidatas', fn ($c) => $c->count() === 1)
            ->set('addSearch', 'BK-1')
            ->assertViewHas('candidatas', fn ($c) => $c->count() === 1)
            ->set('addSearch', 'nada')
            ->assertViewHas('candidatas', fn ($c) => $c->isEmpty());
    }

    public function test_agregar_mete_el_saldo_y_recalcula_el_total(): void
    {
        $id = $this->solicitudConUnCosto();

        $this->detalle($id)->call('addTransaction', 2);

        $this->assertSame([1500.0, 500.0, 500.0, [1 => 1000.0, 2 => 500.0]], [
            (float) PaymentRequest::find($id)->amount,
            (float) PaymentByTransaction::where('request_id', $id)->where('transc_id', 2)->value('amount'),
            (float) DB::table('transaction')->where('transc_id', 2)->value('paid_amount'),
            $this->reparto($id),
        ]);
    }

    /** El importe por omisión es el saldo REAL: descuenta lo que otra solicitud ya pagó. */
    public function test_agregar_respeta_lo_que_otra_solicitud_ya_pago(): void
    {
        $id = $this->solicitudConUnCosto();
        DB::table('payment_request')->insert(['request_id' => 90, 'type' => 2, 'provider_id' => 1, 'currency_id' => 1, 'amount' => 200, 'paid' => 1]);
        DB::table('payments_by_transaction')->insert(['request_id' => 90, 'transc_id' => 2, 'amount' => 200, 'paid' => 1]);

        $this->detalle($id)->call('addTransaction', 2);

        $this->assertSame(300.0, (float) PaymentByTransaction::where('request_id', $id)->where('transc_id', 2)->value('amount'));
    }

    public function test_no_se_agrega_una_de_otro_proveedor(): void
    {
        $id = $this->solicitudConUnCosto();

        $this->detalle($id)->call('addTransaction', 3)->assertStatus(422);

        $this->assertSame([1], PaymentByTransaction::where('request_id', $id)->pluck('transc_id')->all());
    }

    public function test_no_se_agrega_una_de_otra_divisa(): void
    {
        $id = $this->solicitudConUnCosto();

        $this->detalle($id)->call('addTransaction', 4)->assertStatus(422);
    }

    public function test_no_se_agrega_una_factura_a_una_solicitud_de_costos(): void
    {
        $id = $this->solicitudConUnCosto();
        DB::table('transaction')->insert([
            'transc_id' => 6, 'booking' => 1, 'tran_type' => 0, 'vendor' => 1, 'customer' => 1,
            'account' => 1, 'tran_number' => 'F-6', 'tran_date' => '2026-01-15',
        ]);
        DB::table('charge')->insert(['charge_id' => 6, 'transaction' => 6, 'type' => 1, 'quantity' => 1, 'price' => 100]);

        $this->detalle($id)->call('addTransaction', 6)->assertStatus(422);
    }

    public function test_no_se_agrega_dos_veces_la_misma(): void
    {
        $id = $this->solicitudConUnCosto();

        $this->detalle($id)->call('addTransaction', 1)->assertStatus(422);

        $this->assertSame(1000.0, (float) PaymentRequest::find($id)->amount);
    }

    public function test_no_se_agrega_una_saldada(): void
    {
        $id = $this->solicitudConUnCosto();
        $this->conCostoSaldado();

        $this->detalle($id)->call('addTransaction', 5)->assertStatus(422);
    }

    public function test_no_se_agrega_una_cancelada(): void
    {
        $id = $this->solicitudConUnCosto();
        DB::table('transaction')->where('transc_id', 2)->update(['cancelled' => 1]);

        $this->detalle($id)->call('addTransaction', 2)->assertStatus(422);
    }

    public function test_a_una_pagada_no_se_le_agrega(): void
    {
        $id = $this->solicitudConUnCosto();
        PaymentRequest::whereKey($id)->update(['paid' => 1, 'opened' => 0]);

        $this->detalle($id)->call('addTransaction', 2)->assertStatus(422);
    }

    public function test_quien_no_es_administrador_no_agrega(): void
    {
        $id = $this->solicitudConUnCosto();

        $this->detalle($id, User::ROLE_USER)->call('addTransaction', 2)->assertForbidden();
    }

    public function test_sin_seleccion_la_pantalla_responde_404(): void
    {
        $this->actingAs($this->usuario());

        Livewire::withQueryParams(['ids' => ''])->test(PaymentRequestForm::class)->assertNotFound();
    }

    // ------------------------------------------------- Ciclo de vida

    private function conSolicitud(): int
    {
        $this->formulario([1, 2])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save');

        return (int) PaymentRequest::first()->request_id;
    }

    private function listado(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(PaymentRequestList::class);
    }

    public function test_marcar_como_pagada(): void
    {
        $id = $this->conSolicitud();

        $this->listado()->call('markPaid', $id);

        $this->assertSame(1, (int) PaymentRequest::find($id)->paid);
    }

    public function test_una_solicitud_pagada_no_se_vuelve_a_pagar(): void
    {
        $id = $this->conSolicitud();

        $this->listado()->call('markPaid', $id);
        $this->listado()->call('markPaid', $id)->assertHasErrors('markPaid')->assertSee(__('La solicitud ya está pagada.'));
    }

    public function test_el_listado_filtra_tambien_por_el_numero_cero(): void
    {
        $this->conSolicitud();

        $this->listado()->set('number', '0')->assertViewHas('filas', fn ($filas) => $filas->isEmpty());
    }

    /** Como el «Amount» de Yii2: el pago a proveedor se enseña en negativo. */
    public function test_el_listado_muestra_el_importe_con_signo(): void
    {
        $id = $this->conSolicitud();
        $importe = number_format((float) PaymentRequest::find($id)->amount, 2);

        // La tarjeta móvil pega la divisa al importe: así no se confunde con el
        // total pagado, que ya venía con signo.
        $this->listado()->assertSeeHtml('-'.$importe.' MXN');
    }

    public function test_reabrir_una_solicitud_pagada(): void
    {
        $id = $this->conSolicitud();

        $this->listado()->call('markPaid', $id);
        $this->listado()->call('reopen', $id);

        $this->assertSame(0, (int) PaymentRequest::find($id)->paid);
    }

    /** Una solicitud borrada no puede seguir pagando: sus renglones se van con ella. */
    public function test_borrar_una_solicitud_suelta_sus_transacciones(): void
    {
        $id = $this->conSolicitud();

        $this->listado(User::ROLE_SUPER_ADMIN)->call('delete', $id);

        $this->assertSame(0, PaymentRequest::count());
        $this->assertSame(0, PaymentByTransaction::where('request_id', $id)->count());
    }

    /**
     * Al guardar se vuelve al listado ya filtrado por el número recién creado y
     * señalando esa solicitud: entre miles de renglones, si no, no se encuentra.
     */
    public function test_al_guardar_vuelve_al_listado_filtrado_por_la_nueva(): void
    {
        $solicitud = $this->formulario([1, 2])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')
            ->assertHasNoErrors();

        $id = (int) PaymentRequest::first()->request_id;

        $solicitud->assertRedirect(route('payments.requests', ['num' => 'CHQ-100', 'nueva' => $id]));
    }

    public function test_el_verde_señala_solo_a_la_recien_creada(): void
    {
        $id = $this->conSolicitud();

        // Una segunda solicitud, para comprobar que el verde no se contagia.
        $this->formulario([3])
            ->set('number', 'CHQ-200')->set('date', '2026-01-21')->set('bankId', '1')
            ->call('save');

        $this->actingAs($this->usuario());

        $html = Livewire::withQueryParams(['nueva' => $id])
            ->test(PaymentRequestList::class)
            ->html();

        $this->assertSame(
            2,
            substr_count($html, 'row-new'),
            'Solo la recién creada va en verde (aparece dos veces: la tarjeta de móvil y el renglón de la tabla).'
        );
    }

    /** El verde es «de ese momento»: se apaga en cuanto se toca un filtro. */
    public function test_el_verde_se_apaga_al_tocar_un_filtro(): void
    {
        $id = $this->conSolicitud();
        $this->actingAs($this->usuario());

        $componente = Livewire::withQueryParams(['nueva' => $id])->test(PaymentRequestList::class);

        $this->assertStringContainsString('row-new', $componente->html());

        $componente->set('paid', '1');

        $this->assertStringNotContainsString('row-new', $componente->html());
    }

    public function test_el_listado_filtra_por_numero(): void
    {
        $this->conSolicitud();
        $this->actingAs($this->usuario());

        Livewire::test(PaymentRequestList::class)
            ->assertSee('CHQ-100')
            ->set('number', 'CHQ-999')
            ->assertDontSee('CHQ-100')
            ->set('number', 'CHQ-100')
            ->assertSee('CHQ-100');
    }

    /** El filtro viaja en la dirección: es lo que hace útil la vuelta al listado. */
    public function test_el_numero_llega_desde_la_direccion(): void
    {
        $this->conSolicitud();
        $this->actingAs($this->usuario());

        Livewire::withQueryParams(['num' => 'CHQ-100'])
            ->test(PaymentRequestList::class)
            ->assertSet('number', 'CHQ-100')
            ->assertSee('CHQ-100');

        Livewire::withQueryParams(['num' => 'CHQ-999'])
            ->test(PaymentRequestList::class)
            ->assertDontSee('CHQ-100');
    }

    public function test_una_solicitud_pagada_no_se_borra(): void
    {
        $id = $this->conSolicitud();

        $this->listado()->call('markPaid', $id);
        // El motivo sale arriba del listado; el 422 dejaba la pantalla sin explicación.
        $this->listado(User::ROLE_SUPER_ADMIN)->call('delete', $id)->assertHasErrors('markPaid');

        $this->assertSame(1, PaymentRequest::count());
    }

    // ------------------------------------------ Fechas y revaluación (B1, B2)

    /** Arranca en el año en curso, pero «Limpiar filtros» deja la pantalla sin rango. */
    public function test_limpiar_filtros_deja_la_lista_sin_rango(): void
    {
        $this->listado()
            ->assertSet('dates', now()->startOfYear()->format('d/m/Y').' - '.now()->format('d/m/Y'))
            ->call('clearFilters')
            ->assertSet('dates', '')
            ->assertSet('allYears', true);
    }

    /** «Ver todos los años» sobrevive al viaje al detalle y de vuelta. */
    public function test_ver_todos_los_anios_viaja_en_la_direccion(): void
    {
        $url = $this->listado()->call('verTodosLosAnios')->assertSet('dates', '')->instance()->currentUrl();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametros);

        $this->actingAs($this->usuario());

        Livewire::withQueryParams($parametros)->test(PaymentRequestList::class)->assertSet('dates', '');
    }

    /** La revaluación arranca en hoy, como el `date_pay` del `actionIndex` original. */
    public function test_la_revaluacion_arranca_en_hoy(): void
    {
        $this->conSolicitud();

        $this->listado()
            ->assertSet('datePay', now()->format('d/m/Y'))
            ->assertViewHas('filas', fn ($filas) => $filas->first()->pay_tc !== null);
    }

    // ------------------------------------------------ Tipo de cambio propio (B3)

    /** El costo 4 está en dólares; el TC registrado para el 15/01/2026 es 20. */
    public function test_con_tc_propio_la_solicitud_se_valua_con_el(): void
    {
        $this->formulario([4])
            ->set('number', 'CHQ-USD')->set('date', '2026-01-15')->set('bankId', '1')
            ->set('customTc', true)
            ->set('tcValue', '18.5')
            ->call('save')
            ->assertHasNoErrors();

        $solicitud = PaymentRequest::first();
        $fila = $this->listado()->viewData('filas')->first();

        $this->assertSame([1, 18.5, 18.5, -5550.0], [
            (int) $solicitud->custom_tc,
            (float) $solicitud->tc_value,
            (float) $fila->exchange_value,
            (float) $fila->total_paid,
        ]);
    }

    public function test_sin_tc_propio_se_anota_el_del_dia(): void
    {
        $this->formulario([4])
            ->set('number', 'CHQ-USD')->set('date', '2026-01-15')->set('bankId', '1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([0, 20.0], [(int) PaymentRequest::first()->custom_tc, (float) PaymentRequest::first()->tc_value]);
    }

    public function test_el_tc_propio_exige_su_valor(): void
    {
        $this->formulario([4])
            ->set('number', 'CHQ-USD')->set('date', '2026-01-15')->set('bankId', '1')
            ->set('customTc', true)
            ->call('save')
            ->assertHasErrors('tcValue');
    }

    // ------------------------------------------------ Reportes de pagos (B8, B9)

    /** Una solicitud pagada de 1,500 MXN al proveedor 1. */
    private function conSolicitudPagada(): void
    {
        $id = $this->conSolicitud();
        $this->listado()->call('markPaid', $id);
    }

    public function test_el_reporte_por_proveedor_filtra_por_proveedor_y_enseña_la_divisa(): void
    {
        $this->conSolicitudPagada();

        Livewire::test(PaymentsReport::class, ['mode' => 'vendor'])
            ->assertViewHas('filas', fn ($filas) => $filas->count() === 1
                && $filas->first()->prefix === 'MXN'
                && (float) $filas->first()->amount_original_paid === 1500.0)
            ->set('providerId', '2')
            ->assertViewHas('filas', fn ($filas) => $filas->isEmpty())
            ->assertSee(__('Importe natural'));
    }

    /** Los saldos por banco: cobros suman, pagos restan, hasta la fecha de corte. */
    public function test_el_reporte_general_trae_los_saldos_por_banco(): void
    {
        $this->conSolicitudPagada();

        Livewire::test(PaymentsReport::class, ['mode' => 'general'])
            ->assertSee(__('Saldos por banco'))
            ->assertViewHas('saldos', fn ($saldos) => $saldos->pluck('total', 'bank_name')->map(fn ($v) => (float) $v)->all() === ['BBVA MXN' => -1500.0])
            ->set('dates', '01/01/2026 - 19/01/2026')
            ->assertViewHas('saldos', fn ($saldos) => (float) $saldos->first()->total === 0.0);
    }

    /**
     * Regresión: el `updated()` de la tabla se dispara con CUALQUIER propiedad y
     * limpiaba la selección, así que al marcar una casilla Livewire la vaciaba en
     * el mismo viaje de ida y vuelta y en pantalla se veía desmarcarse sola.
     */
    public function test_marcar_una_casilla_no_borra_la_seleccion(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'bill'])
            ->set('selected', [1])
            ->assertSet('selected', [1])
            ->set('selected', [1, 2])
            ->assertSet('selected', [1, 2]);
    }

    /**
     * La cabecera trae el tirador de ancho en cada columna y las columnas de
     * texto libre salen recortadas: hay bookings que encadenan diez referencias
     * y estiraban la tabla entera.
     */
    public function test_la_cabecera_permite_ajustar_el_ancho(): void
    {
        $this->actingAs($this->usuario());

        $html = Livewire::test(TransactionTable::class, ['screen' => 'bill'])->html();

        $this->assertStringContainsString('columnResizer(', $html);
        // 23 columnas: las del `bill.php` original (Tipo, Non Dec, PDF/XML,
        // Solicitud, Total natural y Saldo) más la utilidad del booking.
        $this->assertSame(23, substr_count($html, 'cursor-col-resize'), 'Cada columna necesita su tirador.');
        $this->assertStringContainsString('truncate', $html);
    }

    /**
     * Desde el listado se tiene que poder ABRIR la transacción, y tiene que
     * verse que se puede: el enlace existía pero iba del mismo color que el
     * texto normal, así que nadie lo encontraba.
     */
    public function test_el_numero_abre_la_transaccion_y_parece_enlace(): void
    {
        $this->actingAs($this->usuario());

        $html = Livewire::test(TransactionTable::class, ['screen' => 'bill'])->html();

        $this->assertStringContainsString(route('transactions.show', 1), $html);

        // Se busca ESE enlace y se mira su clase: que exista no basta, tiene que
        // verse. Antes iba en `text-ink`, indistinguible del texto normal.
        preg_match_all('/<a href="[^"]*'.preg_quote(route('transactions.show', 1), '/').'"[^>]*class="([^"]*)"/', $html, $m);

        $this->assertCount(2, $m[1], 'Se esperan dos enlaces al detalle: la tarjeta de móvil y el renglón de la tabla.');

        foreach ($m[1] as $clases) {
            $this->assertMatchesRegularExpression(
                '/(^|\s)text-brand(\s|$)/',
                $clases,
                "El número de la transacción debe ir con el color de enlace, no solo al pasar el ratón (clases: {$clases})."
            );
        }
    }

    /** El renglón marcado se distingue a simple vista, no solo por la casilla. */
    public function test_el_renglon_marcado_se_pinta(): void
    {
        $this->actingAs($this->usuario());

        $html = Livewire::test(TransactionTable::class, ['screen' => 'bill'])
            ->set('selected', [1])
            ->html();

        $this->assertSame(1, substr_count($html, 'row-picked'), 'Solo el renglón marcado debería ir resaltado.');
    }

    /**
     * «Cancelar» devuelve a la pantalla de la que se llegó, con su filtro.
     *
     * Antes caía siempre en el listado de solicitudes aunque se viniera de
     * Costos, y había que rehacer el filtro a mano.
     */
    public function test_cancelar_vuelve_a_donde_se_venia(): void
    {
        $this->actingAs($this->usuario());

        $origen = Livewire::test(TransactionTable::class, ['screen' => 'bill'])
            ->set('tranNumber', 'C-1')
            ->set('selected', [1, 2])
            ->call('createPaymentRequest')
            ->effects['redirect'];

        parse_str((string) parse_url($origen, PHP_URL_QUERY), $partes);

        // Lo que importa es que el FILTRO sobreviva al viaje: el destino lleva
        // `&` dentro y si no viajara codificado se partiría en el camino.
        $this->assertStringContainsString('/transacciones/costos', $partes['volver']);
        $this->assertStringContainsString('num=C-1', $partes['volver']);

        $formulario = Livewire::withQueryParams(['ids' => '1,2', 'volver' => $partes['volver']])
            ->test(PaymentRequestForm::class);

        $this->assertSame($partes['volver'], $formulario->instance()->backUrl());
        $formulario->assertSee(e($partes['volver']), false);
    }

    /**
     * El destino llega en la dirección, o sea de fuera: una ruta que apunte a
     * otro sitio se descarta y se cae al listado de solicitudes.
     */
    public function test_no_se_puede_mandar_el_cancelar_fuera_del_sistema(): void
    {
        $this->actingAs($this->usuario());

        foreach (['https://otro-sitio.example/phishing', '//otro-sitio.example', 'javascript:alert(1)'] as $malo) {
            $formulario = Livewire::withQueryParams(['ids' => '1', 'volver' => $malo])
                ->test(PaymentRequestForm::class);

            $this->assertSame(
                route('payments.requests', absolute: false),
                $formulario->instance()->backUrl(),
                "Debería descartarse el destino «{$malo}»."
            );
        }
    }

    /** Cambiar un filtro sí limpia la selección: los renglones marcados ya no están. */
    public function test_cambiar_un_filtro_limpia_la_seleccion(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'bill'])
            ->set('selected', [1, 2])
            ->set('bookingNumber', 'BK-9')
            ->assertSet('selected', []);
    }

    public function test_quien_no_es_administrador_no_toca_las_solicitudes(): void
    {
        $id = $this->conSolicitud();

        $this->listado(User::ROLE_USER)->call('markPaid', $id)->assertForbidden();
        $this->listado(User::ROLE_USER)->call('delete', $id)->assertForbidden();

        $this->assertSame(0, (int) PaymentRequest::find($id)->paid);
    }
}
