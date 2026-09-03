<?php

namespace Tests\Feature\Cfdi;

use App\Livewire\Transactions\TransactionDetail;
use App\Models\Core\Transaction;
use App\Models\User;
use App\Support\Cfdi\PacClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\Support\FakePacClient;
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

        $this->seedFixture();
    }

    private function seedFixture(): void
    {
        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN']]);
        DB::table('exchange')->insert([['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1]]);
        DB::table('booking')->insert([['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10]]);
        DB::table('company')->insert([[
            'company_id' => 1, 'name' => 'FTM', 'business_name' => 'EMPRESA DEMO SA DE CV',
            'rfc' => 'XAXX010101000', 'regimen_fiscal' => '601', 'postal_code' => '44100', 'active' => 1,
        ]]);
        DB::table('client')->insert([[
            'client_id' => 1, 'fullName' => 'Cliente Uno', 'rfc' => 'AAA010101AAA',
            'pay_form' => '03', 'pay_method' => 'PUE', 'invoice_use' => 'G03',
            'regimen_fiscal_id' => '601', 'postal_code' => '44100',
        ]]);
        DB::table('charge_type')->insert([[
            'charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_name' => 'IVA',
            'tax_rate' => 0.16, 'tax_retention' => 0, 'non_deductible' => 0, 'product_code' => '78101800',
        ]]);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'company_id' => 1,
            'account' => 1, 'tran_number' => 'F-1', 'tran_date' => '2026-01-15', 'invoice_type' => 1,
        ]]);
        DB::table('charge')->insert([[
            'charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 2, 'price' => 1000,
            'description' => 'Flete Manzanillo',
        ]]);
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

        $this->assertSame(1, (int) Transaction::find(1)->cancelled);
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
}
