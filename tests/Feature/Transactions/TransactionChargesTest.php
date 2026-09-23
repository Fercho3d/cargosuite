<?php

namespace Tests\Feature\Transactions;

use App\Livewire\Transactions\TransactionDetail;
use App\Models\Core\Charge;
use App\Models\Core\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Conceptos de una transacción: alta, edición y baja.
 *
 * En Yii2 esto era el `ChargeController` en un modal aparte; aquí vive dentro del
 * detalle, así que se prueba a través de ese componente. Como escribe, corre
 * sobre el esquema de pruebas y no contra la copia local de `frego`.
 */
class TransactionChargesTest extends TestCase
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
        ]);

        DB::table('exchange')->insert([
            ['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1],
        ]);

        DB::table('booking')->insert([
            ['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10],
        ]);

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Proveedor Uno']]);

        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_name' => 'IVA', 'tax_rate' => 0.16, 'tax_retention' => 0, 'non_deductible' => 0, 'deleted' => 0],
            ['charge_type_id' => 2, 'charge_type_name' => 'Maniobra', 'tax_name' => 'IVA 0', 'tax_rate' => 0, 'tax_retention' => 0, 'non_deductible' => 0, 'deleted' => 0],
            ['charge_type_id' => 3, 'charge_type_name' => 'Sin servicios', 'tax_name' => 'IVA', 'tax_rate' => 0.16, 'tax_retention' => 0, 'non_deductible' => 0, 'deleted' => 0],
        ]);

        DB::table('service')->insert([
            // Precio pactado: el usuario no lo puede cambiar.
            ['service_id' => 10, 'charge_type_id' => 1, 'client_id' => 1, 'type' => 1, 'price' => 850, 'description' => 'Flete Monterrey', 'active' => 1],
            // Precio abierto (0): lo captura el usuario.
            ['service_id' => 11, 'charge_type_id' => 2, 'client_id' => 1, 'type' => 1, 'price' => 0, 'description' => 'Maniobra variable', 'active' => 1],
            // De un proveedor: no debe aparecer en una factura al cliente.
            ['service_id' => 12, 'charge_type_id' => 1, 'provider_id' => 1, 'type' => 2, 'price' => 700, 'description' => 'Flete proveedor', 'active' => 1],
        ]);

        DB::table('transaction')->insert([
            ['transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
                'tran_number' => 'F-1', 'tran_date' => '2026-01-15', 'invoice_type' => 1],
        ]);
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

    public function test_agregar_un_concepto_actualiza_el_total_del_documento(): void
    {
        $detalle = $this->detalle()
            ->call('addCharge')
            ->set('chargeType', '1')
            ->set('serviceId', '10')
            ->set('quantity', '2')
            ->call('saveCharge')
            ->assertHasNoErrors();

        $cargo = Charge::first();

        $this->assertSame(850.0, $cargo->price, 'El precio lo pone el catálogo.');
        $this->assertSame('Flete Monterrey', $cargo->description, 'La descripción se copia del servicio.');

        // 2 × 850 = 1 700 de subtotal, más 16 % de IVA = 1 972.
        $this->assertEqualsWithDelta(1972.0, (float) $detalle->viewData('fila')->total_amount, 0.01);
    }

    /** `charge.description` es varchar(100); la del servicio puede llegar a 255. */
    public function test_una_descripcion_de_servicio_de_mas_de_100_caracteres_no_se_guarda(): void
    {
        DB::table('service')->where('service_id', 10)->update(['description' => str_repeat('x', 101)]);

        $this->detalle()
            ->call('addCharge')
            ->set('chargeType', '1')
            ->set('serviceId', '10')
            ->call('saveCharge')
            ->assertHasErrors('serviceId');

        $this->assertSame(0, Charge::count());
    }

    /** Las columnas del detalle de Yii2: Pre-Paid, unidad y las dos tasas. */
    public function test_los_conceptos_enseñan_prepagado_unidad_y_tasas(): void
    {
        DB::table('charge_type')->where('charge_type_id', 1)->update(['tax_retention' => 0.04]);
        DB::table('charge')->insert([[
            'charge_id' => 1, 'transaction' => 1, 'type' => 1, 'service_id' => 10, 'quantity' => 1,
            'unit' => 3, 'price' => 850, 'prepaid' => 1, 'description' => 'Flete Monterrey',
        ]]);

        $this->detalle()
            ->assertSee(__('Prepagado'))
            ->assertSee(__('Tasa de retención'))
            ->assertSeeHtml('>'.__('Sí').'</td>')
            ->assertSeeHtml('>3.00</td>')
            ->assertSeeHtml('>16 %</td>')
            ->assertSeeHtml('>4 %</td>');
    }

    public function test_elegir_el_servicio_trae_su_precio(): void
    {
        $this->detalle()
            ->call('addCharge')
            ->set('chargeType', '1')
            ->set('serviceId', '10')
            ->assertSet('price', '850');
    }

    public function test_un_servicio_de_precio_abierto_deja_capturar_el_importe(): void
    {
        $this->detalle()
            ->call('addCharge')
            ->set('chargeType', '2')
            ->set('serviceId', '11')
            ->set('price', '333.50')
            ->set('quantity', '1')
            ->call('saveCharge')
            ->assertHasNoErrors();

        $this->assertSame(333.50, Charge::first()->price);
    }

    public function test_el_importe_acepta_separador_de_miles(): void
    {
        $this->detalle()
            ->call('addCharge')
            ->set('chargeType', '2')
            ->set('serviceId', '11')
            ->set('price', '2,929.91')
            ->set('quantity', '1')
            ->call('saveCharge');

        $this->assertSame(2929.91, Charge::first()->price);
    }

    public function test_cambiar_el_tipo_de_cargo_limpia_el_servicio(): void
    {
        $this->detalle()
            ->call('addCharge')
            ->set('chargeType', '1')
            ->set('serviceId', '10')
            ->set('chargeType', '2')
            ->assertSet('serviceId', '')
            ->assertSet('price', '');
    }

    /**
     * Solo se ofrecen los tipos de cargo que tienen algún servicio contratado con
     * la contraparte del documento: en una factura, los del cliente.
     */
    public function test_solo_se_ofrecen_tipos_de_cargo_con_servicio_de_esa_contraparte(): void
    {
        $tipos = $this->detalle()->viewData('tiposDeCargo');

        $this->assertSame([1 => 'Flete - IVA', 2 => 'Maniobra - IVA 0'], $tipos);
    }

    public function test_editar_un_concepto(): void
    {
        DB::table('charge')->insert([
            'charge_id' => 5, 'transaction' => 1, 'type' => 1, 'service_id' => 10,
            'description' => 'Flete Monterrey', 'quantity' => 1, 'price' => 850,
        ]);

        $this->detalle()
            ->call('editCharge', 5)
            ->assertSet('chargeType', '1')
            ->assertSet('quantity', '1')
            ->set('quantity', '3')
            ->call('saveCharge')
            ->assertHasNoErrors();

        $this->assertSame(3.0, Charge::find(5)->quantity);
        $this->assertSame(1, Charge::count(), 'Editar no debe crear una línea nueva.');
    }

    public function test_borrar_un_concepto(): void
    {
        DB::table('charge')->insert([
            'charge_id' => 5, 'transaction' => 1, 'type' => 1, 'service_id' => 10,
            'description' => 'Flete Monterrey', 'quantity' => 1, 'price' => 850,
        ]);

        $this->detalle()->call('deleteCharge', 5);

        $this->assertSame(0, Charge::count());
    }

    /** Cambiar los importes de una factura ya timbrada rompería el CFDI. */
    public function test_una_transaccion_timbrada_no_deja_tocar_los_conceptos(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['seal' => 'SELLO-CFDI']);
        DB::table('charge')->insert([
            'charge_id' => 5, 'transaction' => 1, 'type' => 1, 'service_id' => 10,
            'description' => 'Flete Monterrey', 'quantity' => 1, 'price' => 850,
        ]);

        $this->detalle()->call('addCharge')->assertForbidden();
        $this->detalle()->call('deleteCharge', 5)->assertForbidden();

        $this->assertSame(1, Charge::count());
    }

    public function test_un_concepto_de_otra_transaccion_no_se_puede_editar(): void
    {
        DB::table('transaction')->insert([
            'transc_id' => 2, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
            'tran_number' => 'F-2', 'tran_date' => '2026-01-15', 'invoice_type' => 1,
        ]);
        DB::table('charge')->insert([
            'charge_id' => 9, 'transaction' => 2, 'type' => 1, 'service_id' => 10,
            'description' => 'De otra', 'quantity' => 1, 'price' => 100,
        ]);

        // La consulta va acotada a la transacción abierta, así que un concepto
        // ajeno sencillamente no existe para ella (en HTTP se traduce a un 404).
        $this->expectException(ModelNotFoundException::class);

        $this->detalle()->call('editCharge', 9);
    }

    // ------------------------------------------------------------ Borrado

    public function test_el_super_administrador_borra_la_transaccion_con_sus_conceptos(): void
    {
        DB::table('charge')->insert([
            'charge_id' => 5, 'transaction' => 1, 'type' => 1, 'service_id' => 10,
            'description' => 'Flete Monterrey', 'quantity' => 1, 'price' => 850,
        ]);

        $this->detalle(User::ROLE_SUPER_ADMIN)
            ->call('deleteTransaction')
            ->assertRedirect(route('transactions.booking', 1));

        $this->assertSame(0, Transaction::count());
        // En la base real los conceptos se van con la llave foránea en cascada;
        // el esquema de pruebas no la declara, así que aquí solo se comprueba
        // que la transacción desapareció.
    }

    public function test_un_administrador_normal_no_borra(): void
    {
        $this->detalle()->call('deleteTransaction')->assertForbidden();

        $this->assertSame(1, Transaction::count());
    }

    public function test_una_transaccion_con_pagos_no_se_borra(): void
    {
        DB::table('charge')->insert([
            'charge_id' => 5, 'transaction' => 1, 'type' => 1, 'service_id' => 10,
            'description' => 'Flete', 'quantity' => 1, 'price' => 100,
        ]);
        DB::table('payment_request')->insert([
            'request_id' => 1, 'date' => '2026-01-16', 'currency_id' => 1, 'type' => 1,
        ]);
        DB::table('payments_by_transaction')->insert([
            'request_id' => 1, 'transc_id' => 1, 'amount' => 50,
        ]);

        $this->detalle(User::ROLE_SUPER_ADMIN)->call('deleteTransaction')->assertForbidden();

        $this->assertSame(1, Transaction::count());
    }
}
