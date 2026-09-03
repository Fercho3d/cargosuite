<?php

namespace Tests\Feature\Cfdi;

use App\Actions\Transactions\SendInvoice;
use App\Livewire\Transactions\TransactionDetail;
use App\Mail\InvoiceMail;
use App\Models\Core\Transaction;
use App\Models\User;
use App\Support\Cfdi\PacClient;
use App\Support\Documentos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\Support\FakePacClient;
use Tests\TestCase;

/**
 * El correo con la factura timbrada: sale solo al timbrar y se puede volver a
 * mandar desde el detalle.
 *
 * **Nunca se habla con el PAC ni sale un correo de verdad**: los dos están
 * sustituidos por dobles.
 */
class InvoiceMailTest extends TestCase
{
    private FakePacClient $pac;

    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        Storage::fake('documentos');
        Http::preventStrayRequests();
        Mail::fake();

        $this->pac = new FakePacClient;
        $this->app->instance(PacClient::class, $this->pac);

        config([
            'marca.correo.copia_facturas' => ['copia@ejemplo.test'],
            'timbrado.produccion' => false,
        ]);

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN']]);
        DB::table('exchange')->insert([['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1]]);
        DB::table('booking')->insert([['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10]]);
        DB::table('company')->insert([[
            'company_id' => 1, 'name' => 'FTM', 'business_name' => 'EMPRESA DEMO SA DE CV',
            'rfc' => 'XAXX010101000', 'regimen_fiscal' => '601', 'postal_code' => '44100', 'active' => 1,
        ]]);
        DB::table('client')->insert([[
            'client_id' => 1, 'fullName' => 'Cliente Uno', 'rfc' => 'AAA010101AAA',
            'email' => 'contacto@cliente.mx', 'email_notification' => 'facturas@cliente.mx',
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

    /** Deja la factura como si ya estuviera timbrada, con sus dos archivos. */
    private function timbrada(): void
    {
        Storage::disk('documentos')->put('transactions/1/pdf/UUID-1.pdf', '%PDF-falso');
        Storage::disk('documentos')->put('transactions/1/pdf/UUID-1.xml', '<cfdi/>');

        Transaction::find(1)->forceFill([
            'seal' => 'UUID-1', 'pdf_attach' => 'UUID-1.pdf', 'xml_attach' => 'UUID-1.xml',
        ])->save();
    }

    public function test_timbrar_le_manda_la_factura_al_cliente(): void
    {
        $this->detalle()->call('stamp')->assertHasNoErrors();

        // ⚠️ El asunto ya depende del idioma de los DOCUMENTOS, no del de la
        // aplicación: `envelope()` a secas lo devuelve en el idioma en curso —en
        // pruebas, español— y la comparación fallaría aunque el correo sí se
        // haya mandado. Se pregunta en el idioma que el Mailable declara.
        Mail::assertSent(InvoiceMail::class, function ($correo) {
            $asunto = Documentos::conIdioma(fn () => $correo->envelope()->subject);

            return $asunto === 'Invoice Booking [BK-1] ' && count($correo->attachments()) === 2;
        });

        Mail::assertSent(InvoiceMail::class, fn ($correo) => $correo->locale === config('marca.idioma_documentos'));
    }

    /**
     * Mientras el timbrado sea de pruebas, los documentos no son fiscales: el
     * correo va a la copia interna y **el cliente no recibe nada**.
     */
    public function test_en_pruebas_la_factura_no_le_llega_al_cliente(): void
    {
        $this->detalle()->call('stamp');

        Mail::assertSent(InvoiceMail::class, fn ($correo) => $correo->hasTo('copia@ejemplo.test')
            && ! $correo->hasTo('facturas@cliente.mx'));
    }

    public function test_en_produccion_va_al_cliente_con_copia_a_la_empresa(): void
    {
        config(['timbrado.produccion' => true]);

        $this->detalle()->call('stamp');

        Mail::assertSent(InvoiceMail::class, fn ($correo) => $correo->hasTo('facturas@cliente.mx')
            && $correo->hasBcc('copia@ejemplo.test'));
    }

    public function test_se_puede_volver_a_mandar_desde_el_detalle(): void
    {
        $this->timbrada();

        $this->detalle()->call('resend');

        Mail::assertSent(InvoiceMail::class);
    }

    public function test_una_factura_sin_documentos_no_se_manda(): void
    {
        $this->assertSame(
            SendInvoice::SIN_DOCUMENTOS,
            app(SendInvoice::class)->handle(Transaction::find(1), 'BK-1'),
        );

        Mail::assertNothingSent();
    }

    public function test_sin_destinatarios_no_se_manda(): void
    {
        config(['timbrado.produccion' => true]);
        DB::table('client')->where('client_id', 1)->update(['email' => null, 'email_notification' => null]);
        $this->timbrada();

        $this->assertSame(
            SendInvoice::SIN_DESTINATARIOS,
            app(SendInvoice::class)->handle(Transaction::find(1), 'BK-1'),
        );

        Mail::assertNothingSent();
    }

    public function test_quien_no_es_administrador_no_reenvia(): void
    {
        $this->timbrada();

        $this->detalle(User::ROLE_USER)->call('resend')->assertForbidden();

        Mail::assertNothingSent();
    }
}
