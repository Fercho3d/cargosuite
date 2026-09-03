<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\PortalDocument;
use App\Livewire\Portal\PortalHome;
use App\Models\Core\PaymentByTransaction;
use App\Models\Core\PaymentRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Lo que el portal viejo existía para hacer: que el proveedor suba su factura
 * contra el costo que Frego le registró, y que después pida su pago.
 *
 * El escenario cabe en la cabeza: dos costos del proveedor 1 en pesos y uno en
 * dólares, más una factura del cliente 1.
 */
class VendorInvoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        Storage::fake('documentos');
        $this->seedFixture();
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
        DB::table('bank')->insert([['bank_id' => 1, 'bank_name' => 'BBVA', 'active' => 1, 'default' => 1]]);
        DB::table('booking')->insert([['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10]]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('provider')->insert([
            ['provider_id' => 1, 'fullName' => 'Proveedor Uno'],
            ['provider_id' => 2, 'fullName' => 'Proveedor Dos'],
        ]);
        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0, 'tax_retention' => 0, 'non_deductible' => 0],
        ]);

        // 1 y 2: costos del proveedor 1 en pesos. 3: costo en dólares.
        // 4: costo de OTRO proveedor. 5: factura del cliente 1.
        $documentos = [
            [1, 1, 1, 1, 1000, 'C-1'],
            [2, 1, 1, 1, 500, 'C-2'],
            [3, 1, 1, 2, 300, 'C-3'],
            [4, 1, 2, 1, 700, 'C-4'],
        ];

        foreach ($documentos as [$id, $booking, $proveedor, $cuenta, $importe, $numero]) {
            DB::table('transaction')->insert([
                'transc_id' => $id, 'booking' => $booking, 'tran_type' => 1, 'vendor' => $proveedor,
                'account' => $cuenta, 'tran_number' => $numero, 'tran_date' => '2026-01-15',
                'pdf_attach' => '', 'xml_attach' => null,
            ]);
            DB::table('charge')->insert([
                'charge_id' => $id, 'transaction' => $id, 'type' => 1, 'quantity' => 1, 'price' => $importe,
            ]);
        }

        DB::table('transaction')->insert([
            'transc_id' => 5, 'booking' => 1, 'tran_type' => 0, 'customer' => 1,
            'account' => 1, 'tran_number' => 'F-5', 'tran_date' => '2026-01-15', 'pdf_attach' => '',
        ]);
        DB::table('charge')->insert([
            'charge_id' => 5, 'transaction' => 5, 'type' => 1, 'quantity' => 1, 'price' => 900,
        ]);
    }

    private function proveedor(int $providerId = 1): User
    {
        $usuario = new User;
        $usuario->usr_id = 70;
        $usuario->role = 15;
        $usuario->access = User::ACCESS_PROVIDER;
        $usuario->provider_id = $providerId;

        return $usuario;
    }

    private function cliente(): User
    {
        $usuario = new User;
        $usuario->usr_id = 80;
        $usuario->role = 12;
        $usuario->access = User::ACCESS_CLIENT;
        $usuario->client_id = 1;

        return $usuario;
    }

    /** @param  int[]  $conArchivos */
    private function conArchivosSubidos(array $conArchivos): void
    {
        foreach ($conArchivos as $id) {
            DB::table('transaction')->where('transc_id', $id)
                ->update(['pdf_attach' => "f{$id}.pdf", 'xml_attach' => "f{$id}.xml"]);
        }
    }

    // ------------------------------------------------------- subir factura

    public function test_el_proveedor_sube_su_factura(): void
    {
        $this->actingAs($this->proveedor());

        Livewire::test(PortalDocument::class, ['transaction' => 1])
            ->set('tranNumber', 'A-777')
            ->set('tranDate', '2026-01-20')
            ->set('pdf', UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $fila = DB::table('transaction')->where('transc_id', 1)->first();

        $this->assertSame('A-777', $fila->tran_number);
        $this->assertSame('factura.pdf', $fila->pdf_attach);
        Storage::disk('documentos')->assertExists('transactions/1/pdf/factura.pdf');
    }

    /** Sin comprobante no hay nada que guardar: es la regla del original. */
    public function test_sin_pdf_no_se_guarda(): void
    {
        $this->actingAs($this->proveedor());

        Livewire::test(PortalDocument::class, ['transaction' => 1])
            ->set('tranNumber', 'A-777')
            ->set('tranDate', '2026-01-20')
            ->call('save')
            ->assertHasErrors('pdf');

        $this->assertSame('', DB::table('transaction')->where('transc_id', 1)->value('tran_number') === 'A-777' ? 'cambió' : '');
    }

    /**
     * El documento de otro proveedor no existe para esta cuenta: 404, no 403.
     * Un 403 confirmaría que el identificador es bueno.
     */
    public function test_no_se_ve_el_documento_de_otro_proveedor(): void
    {
        $this->actingAs($this->proveedor());

        $this->get(route('portal.document', 4))->assertNotFound();
    }

    public function test_el_cliente_no_sube_nada(): void
    {
        $this->actingAs($this->cliente());

        Livewire::test(PortalDocument::class, ['transaction' => 5])
            ->set('tranNumber', 'X')
            ->set('tranDate', '2026-01-20')
            ->call('save')
            ->assertForbidden();
    }

    // -------------------------------------------------------- pedir pago

    public function test_el_proveedor_pide_el_pago_de_sus_costos(): void
    {
        $this->conArchivosSubidos([1, 2]);
        $this->actingAs($this->proveedor());

        Livewire::test(PortalHome::class)
            ->set('selected', [1, 2])
            ->call('requestPayment')
            ->assertHasNoErrors();

        $solicitud = PaymentRequest::first();

        $this->assertSame(2, (int) $solicitud->type, 'Una solicitud de proveedor es de tipo 2.');
        $this->assertSame(1, (int) $solicitud->provider_id);
        $this->assertSame(1, (int) $solicitud->opened, 'Queda abierta: Frego decide cuándo se paga.');
        $this->assertSame(0, (int) $solicitud->paid);
        $this->assertSame(1500.0, (float) $solicitud->amount);

        // El renglón va en CERO: pedir no es cobrar.
        $this->assertSame(2, PaymentByTransaction::where('request_id', $solicitud->request_id)->count());
        $this->assertSame(0.0, (float) PaymentByTransaction::where('transc_id', 1)->value('amount'));

        $costo = DB::table('transaction')->where('transc_id', 1)->first();
        $this->assertSame(1, (int) $costo->payment_request);
        $this->assertNotNull($costo->request_at);
        $this->assertNull($costo->paid_amount, 'Pedir el pago no aplica ningún importe.');
    }

    public function test_no_se_piden_juntos_documentos_de_distinta_divisa(): void
    {
        $this->conArchivosSubidos([1, 3]);
        $this->actingAs($this->proveedor());

        Livewire::test(PortalHome::class)
            ->set('selected', [1, 3])
            ->call('requestPayment')
            ->assertHasErrors('seleccion');

        $this->assertSame(0, PaymentRequest::count());
    }

    /** Sin los dos archivos no se puede pedir: la solicitud viaja con el comprobante. */
    public function test_hace_falta_el_pdf_y_el_xml(): void
    {
        $this->conArchivosSubidos([1]);
        DB::table('transaction')->where('transc_id', 1)->update(['xml_attach' => null]);
        $this->actingAs($this->proveedor());

        Livewire::test(PortalHome::class)
            ->set('selected', [1])
            ->call('requestPayment')
            ->assertHasErrors('seleccion');

        $this->assertSame(0, PaymentRequest::count());
    }

    /** Los identificadores llegan de la petición: no se les cree nada. */
    public function test_no_se_puede_pedir_el_pago_de_un_documento_ajeno(): void
    {
        $this->conArchivosSubidos([1, 4]);
        $this->actingAs($this->proveedor());

        Livewire::test(PortalHome::class)
            ->set('selected', [4])
            ->call('requestPayment')
            ->assertHasErrors('seleccion');

        $this->assertSame(0, PaymentRequest::count());
    }

    public function test_marcar_una_casilla_no_borra_la_seleccion(): void
    {
        $this->actingAs($this->proveedor());

        Livewire::test(PortalHome::class)
            ->set('selected', [1])
            ->assertSet('selected', [1])
            ->set('selected', [1, 2])
            ->assertSet('selected', [1, 2]);
    }

    // ------------------------------------------------ dar por recibida

    public function test_el_cliente_da_por_recibida_su_factura(): void
    {
        $this->actingAs($this->cliente());

        Livewire::test(PortalHome::class)->call('markProcessed', 5);

        $this->assertSame(1, (int) DB::table('transaction')->where('transc_id', 5)->value('processed'));
    }

    public function test_el_cliente_no_marca_una_factura_ajena(): void
    {
        DB::table('transaction')->where('transc_id', 5)->update(['customer' => 99]);
        $this->actingAs($this->cliente());

        Livewire::test(PortalHome::class)->call('markProcessed', 5)->assertNotFound();
    }

    public function test_un_proveedor_no_marca_facturas(): void
    {
        $this->actingAs($this->proveedor());

        Livewire::test(PortalHome::class)->call('markProcessed', 5)->assertForbidden();
    }
}
