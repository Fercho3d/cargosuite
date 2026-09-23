<?php

namespace Tests\Feature\Payments;

use App\Models\Core\PaymentRequest;
use App\Models\User;
use App\Support\Pdf\PaymentRequestDocument;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** La solicitud de pago impresa: el cheque y las facturas que cubre. */
class PaymentRequestDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1]]);
        DB::table('exchange')->insert([['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1]]);
        DB::table('bank')->insert([['bank_id' => 1, 'bank_name' => 'BBVA', 'account_number' => '0123456789']]);
        DB::table('provider')->insert([['provider_id' => 3, 'fullName' => 'Autotransportes del Norte', 'type_id' => 2]]);
        DB::table('booking')->insert([['booking_id' => 1, 'booking_number' => 'BK-77', 'client' => 1, 'mode' => 10]]);
        DB::table('charge_type')->insert([['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0]]);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 1, 'vendor' => 3, 'account' => 1,
            'tran_number' => 'C-1', 'tran_date' => '2026-01-15', 'request_id' => 9, 'payment_request' => 1,
        ]]);
        DB::table('charge')->insert([[
            'charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 1, 'price' => 1234.56,
        ]]);
        DB::table('payments_by_transaction')->insert([[
            'request_id' => 9, 'transc_id' => 1, 'amount' => 1234.56, 'paid' => 0,
        ]]);
        DB::table('payment_request')->insert([[
            'request_id' => 9, 'number' => '1050', 'amount' => 1234.56, 'provider_id' => 3,
            'currency_id' => 1, 'bank_id' => 1, 'type' => 2, 'date' => '2026-01-20', 'opened' => 1,
        ]]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    public function test_el_cheque_lleva_el_importe_con_letra_y_los_centavos_aparte(): void
    {
        $html = app(PaymentRequestDocument::class)->html(PaymentRequest::findOrFail(9));

        $this->assertStringContainsString('Autotransportes del Norte', $html);
        $this->assertStringContainsString('$ 1,234.56', $html);
        // En inglés y rellenado con guiones, como en el original.
        $this->assertStringContainsString('one thousand two hundred thirty-four', $html);
        $this->assertStringContainsString('0.56/100', $html);
        $this->assertStringContainsString('0123456789', $html);
        $this->assertStringContainsString('20/01/2026', $html);
        $this->assertStringContainsString('BK-77', $html);
    }

    /** El original solo leía el proveedor: el cobro a cliente salía sin beneficiario. */
    public function test_el_cobro_a_cliente_lleva_el_nombre_del_cliente(): void
    {
        DB::table('client')->insert([['client_id' => 5, 'fullName' => 'Importadora del Pacífico']]);
        DB::table('payment_request')->where('request_id', 9)->update(['type' => 1, 'provider_id' => null, 'client_id' => 5]);

        $html = app(PaymentRequestDocument::class)->html(PaymentRequest::findOrFail(9));

        $this->assertStringContainsString('Importadora del Pacífico', $html);
    }

    public function test_se_sirve_como_pdf(): void
    {
        $respuesta = $this->actingAs($this->usuario())->get('/pagos/solicitudes/9/documento.pdf');

        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    public function test_quien_no_es_administrador_no_lo_imprime(): void
    {
        $this->actingAs($this->usuario(User::ROLE_USER))
            ->get('/pagos/solicitudes/9/documento.pdf')
            ->assertForbidden();
    }
}
