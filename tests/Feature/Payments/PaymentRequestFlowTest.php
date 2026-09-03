<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\PaymentRequestForm;
use App\Livewire\Payments\PaymentRequestList;
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
        Http::fake(['sidofqa.segob.gob.mx/*' => Http::response(['ListaIndicadores' => []])]);
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

    public function test_el_pago_queda_aplicado_a_cada_transaccion(): void
    {
        $this->formulario([1, 2])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1000.0, (float) DB::table('transaction')->where('transc_id', 1)->value('paid_amount'));
        $this->assertSame(500.0, (float) DB::table('transaction')->where('transc_id', 2)->value('paid_amount'));
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
     * Una transacción YA SALDADA sigue admitiendo importe, hasta el total del
     * documento.
     *
     * Es la rama `modeOpen` de `validateAmountToPay()` en Yii2, y esta pantalla
     * la enciende siempre (`_transactions.php` fija `'modeopen' => 1`): dentro de
     * una solicitud, lo ya aplicado se puede volver a repartir. Sin esto el
     * renglón quedaba trabado — el 0 lo rechazaba una regla y cualquier otra
     * cifra la otra.
     */
    public function test_una_transaccion_saldada_admite_hasta_el_total(): void
    {
        $this->conCostoSaldado();

        $this->formulario([5])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.5', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1000.0, (float) PaymentRequest::where('request_id', '>', 90)->value('amount'));
    }

    public function test_una_transaccion_saldada_no_admite_mas_del_total(): void
    {
        $this->conCostoSaldado();

        $this->formulario([5])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->set('amounts.5', '1000.01')
            ->call('save')
            ->assertHasErrors('amounts.5');
    }

    /**
     * El importe propuesto entra tal cual aunque sea 0: en Yii2 la validación
     * corre al teclear en la casilla, así que el valor por omisión nunca se
     * comprueba y una transacción saldada se manda con 0 sin protestar.
     */
    public function test_el_importe_propuesto_pasa_aunque_sea_cero(): void
    {
        $this->conCostoSaldado();

        $this->formulario([5])
            ->set('number', 'CHQ-100')->set('date', '2026-01-20')->set('bankId', '1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, PaymentRequest::where('request_id', '>', 90)->count());
    }

    /** Un costo de 1,160 (1,000 + IVA) cobrado por completo por otra solicitud. */
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
        $this->listado()->call('markPaid', $id)->assertStatus(422);
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

        $this->listado()->call('delete', $id);

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
        $this->listado()->call('delete', $id)->assertStatus(422);

        $this->assertSame(1, PaymentRequest::count());
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
        $this->assertSame(16, substr_count($html, 'cursor-col-resize'), 'Cada columna necesita su tirador.');
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
