<?php

namespace Tests\Feature\Transactions;

use App\Livewire\Transactions\TransactionForm;
use App\Models\Core\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Alta y edición de transacciones, sobre datos diminutos hechos a mano.
 *
 * No corre contra la base real a propósito: estas pruebas **escriben**, y la copia
 * local de `frego` es la que usan las pruebas de paridad para comparar celda por
 * celda contra el sistema original.
 */
class TransactionFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        $this->seedFixture();

        // Guardar consulta el tipo de cambio del DOF; en pruebas no se sale a red.
        Http::preventStrayRequests();
        Http::fake(['sidofqa.segob.gob.mx/*' => Http::response(['ListaIndicadores' => []])]);
    }

    private function seedFixture(): void
    {
        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN'],
        ]);

        DB::table('company')->insert([
            ['company_id' => 1, 'name' => 'FTM', 'rfc' => 'AAA010101AAA'],
            ['company_id' => 2, 'name' => 'FTA', 'rfc' => 'BBB010101BBB'],
        ]);

        DB::table('booking')->insert([
            ['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10],
            ['booking_id' => 2, 'booking_number' => 'BK-2', 'client' => 1, 'mode' => 10],
        ]);

        DB::table('client')->insert([
            ['client_id' => 1, 'fullName' => 'Cliente Uno'],
        ]);

        DB::table('provider')->insert([
            ['provider_id' => 1, 'fullName' => 'Proveedor Uno'],
            ['provider_id' => 2, 'fullName' => 'Proveedor Dos'],
        ]);

        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'IVA 16', 'tax_rate' => 0.16, 'tax_retention' => 0, 'non_deductible' => 0],
        ]);

        DB::table('exchange')->insert([
            ['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1],
        ]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        // Sin tabla `users` en el esquema de pruebas: para autenticar basta un
        // modelo en memoria con su llave, que es lo único que se usa al guardar.
        $usuario = new User;
        $usuario->usr_id = 7;
        $usuario->role = $rol;

        return $usuario;
    }

    /**
     * Abre el formulario como lo abre el navegador: al editar, el id viaja en la
     * ruta; al crear, el booking y el tipo viajan en la cadena de consulta.
     *
     * @param  array<string, mixed>  $campos
     */
    private function formulario(array $parametros, array $campos = []): Testable
    {
        $componente = isset($parametros['transaction'])
            ? Livewire::test(TransactionForm::class, $parametros)
            : Livewire::withQueryParams($parametros)->test(TransactionForm::class);

        foreach ($campos as $campo => $valor) {
            $componente->set($campo, $valor);
        }

        return $componente;
    }

    public function test_una_factura_nueva_recibe_folio_consecutivo(): void
    {
        $this->actingAs($this->usuario());

        DB::table('transaction')->insert([
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'invoice' => 41, 'tran_number' => 'F-41',
            'tran_date' => '2026-01-10', 'account' => 1, 'invoice_type' => 1,
        ]);

        $this->formulario(['booking' => 1, 'tipo' => 'factura'], [
            'tranDate' => '2026-01-15',
            'accountId' => '1',
            'customerId' => '1',
            'companyId' => '1',
            'invoiceType' => (string) Transaction::INVOICE_TYPE_NORMAL,
        ])->call('save')->assertHasNoErrors();

        $creada = Transaction::where('transc_id', '>', 1)->first();

        $this->assertSame(42, (int) $creada->invoice);
        $this->assertSame('F-42', $creada->tran_number);
        $this->assertSame(7, (int) $creada->created_by);
        $this->assertSame(1, (int) $creada->open, 'Toda transacción nueva nace abierta.');
    }

    public function test_una_factura_historica_conserva_el_numero_capturado(): void
    {
        $this->actingAs($this->usuario());

        $this->formulario(['booking' => 1, 'tipo' => 'factura'], [
            'tranDate' => '2026-01-15',
            'accountId' => '1',
            'customerId' => '1',
            'invoiceType' => (string) Transaction::INVOICE_TYPE_HISTORY,
            'tranNumber' => 'HIST-9',
        ])->call('save')->assertHasNoErrors();

        $creada = Transaction::first();

        $this->assertSame('HIST-9', $creada->tran_number);
        $this->assertNull($creada->invoice, 'Las históricas no consumen folio.');
    }

    public function test_un_costo_exige_proveedor(): void
    {
        $this->actingAs($this->usuario());

        $this->formulario(['booking' => 1, 'tipo' => 'costo'], [
            'tranDate' => '2026-01-15',
            'accountId' => '1',
        ])->call('save')->assertHasErrors('vendorId');

        $this->assertSame(0, Transaction::count());
    }

    public function test_un_proveedor_no_puede_repetirse_en_el_mismo_booking(): void
    {
        $this->actingAs($this->usuario());

        DB::table('transaction')->insert([
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 1, 'vendor' => 1,
            'tran_number' => 'C-1', 'tran_date' => '2026-01-10', 'account' => 1,
        ]);

        $this->formulario(['booking' => 1, 'tipo' => 'costo'], [
            'tranDate' => '2026-01-15',
            'accountId' => '1',
            'vendorId' => '1',
        ])->call('save')->assertHasErrors('vendorId');

        $this->assertSame(1, Transaction::count());
    }

    public function test_el_mismo_proveedor_si_puede_estar_en_otro_booking(): void
    {
        $this->actingAs($this->usuario());

        DB::table('transaction')->insert([
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 1, 'vendor' => 1,
            'tran_number' => 'C-1', 'tran_date' => '2026-01-10', 'account' => 1,
        ]);

        $this->formulario(['booking' => 2, 'tipo' => 'costo'], [
            'tranDate' => '2026-01-15',
            'accountId' => '1',
            'vendorId' => '1',
        ])->call('save')->assertHasNoErrors();

        $this->assertSame(2, Transaction::count());
    }

    /** El original exime al super administrador para poder corregir a mano. */
    public function test_el_super_administrador_puede_repetir_proveedor(): void
    {
        $this->actingAs($this->usuario(User::ROLE_SUPER_ADMIN));

        DB::table('transaction')->insert([
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 1, 'vendor' => 1,
            'tran_number' => 'C-1', 'tran_date' => '2026-01-10', 'account' => 1,
        ]);

        $this->formulario(['booking' => 1, 'tipo' => 'costo'], [
            'tranDate' => '2026-01-15',
            'accountId' => '1',
            'vendorId' => '1',
        ])->call('save')->assertHasNoErrors();

        $this->assertSame(2, Transaction::count());
    }

    public function test_una_transaccion_timbrada_solo_deja_cambiar_la_compania(): void
    {
        $this->actingAs($this->usuario());

        DB::table('transaction')->insert([
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'company_id' => 1,
            'tran_number' => 'F-1', 'tran_date' => '2026-01-15', 'account' => 1,
            'invoice_type' => 1, 'seal' => 'SELLO-CFDI',
        ]);

        DB::table('charge')->insert([
            'charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 1, 'price' => 100,
        ]);

        $this->formulario(['transaction' => 1], [
            'companyId' => '2',
            'tranNumber' => 'INTENTO-DE-CAMBIO',
        ])->call('save')->assertHasNoErrors();

        $guardada = Transaction::find(1);

        $this->assertSame(2, (int) $guardada->company_id, 'La compañía sí debe cambiar.');
        $this->assertSame('F-1', $guardada->tran_number, 'El resto del documento no se toca.');
    }

    public function test_quien_no_es_administrador_no_guarda(): void
    {
        $this->actingAs($this->usuario(User::ROLE_USER));

        $this->formulario(['booking' => 1, 'tipo' => 'factura'], [
            'tranDate' => '2026-01-15',
            'accountId' => '1',
            'customerId' => '1',
        ])->call('save')->assertForbidden();

        $this->assertSame(0, Transaction::count());
    }

    /**
     * Livewire rellena los campos cuando arranca su JavaScript. Si la vista no
     * los pinta también desde el servidor, el formulario aparece vacío hasta ese
     * momento — y con el JavaScript caído, guardar borraría los datos.
     */
    public function test_el_formulario_pinta_los_valores_desde_el_servidor(): void
    {
        $this->actingAs($this->usuario());

        DB::table('transaction')->insert([
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 1, 'vendor' => 1, 'company_id' => 2,
            'tran_number' => 'C-1', 'tran_date' => '2026-01-15', 'account' => 1,
        ]);

        Livewire::test(TransactionForm::class, ['transaction' => 1])
            ->assertSeeHtml('value="C-1"')
            ->assertSeeHtml('value="2026-01-15"')
            ->assertSeeHtml('<option value="1" selected>Proveedor Uno</option>')
            ->assertSeeHtml('<option value="2" selected>FTA</option>');
    }

    /**
     * `modify-date` del original: con el documento bloqueado, el super
     * administrador todavía corrige la fecha mientras el booking siga abierto.
     */
    public function test_el_super_administrador_corrige_la_fecha_de_una_transaccion_timbrada(): void
    {
        $this->actingAs($this->usuario(User::ROLE_SUPER_ADMIN));

        $this->transaccionTimbrada();

        $this->formulario(['transaction' => 1], ['tranDate' => '2026-02-01'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-02-01', Transaction::find(1)->tran_date->toDateString());
    }

    public function test_un_administrador_normal_no_corrige_la_fecha_de_una_timbrada(): void
    {
        $this->actingAs($this->usuario());

        $this->transaccionTimbrada();

        $this->formulario(['transaction' => 1], ['tranDate' => '2026-02-01'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-01-15', Transaction::find(1)->tran_date->toDateString());
    }

    /** Factura con sello y con cargos: bloqueada para todo menos la compañía. */
    private function transaccionTimbrada(): void
    {
        DB::table('transaction')->insert([
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'company_id' => 1,
            'tran_number' => 'F-1', 'tran_date' => '2026-01-15', 'account' => 1,
            'invoice_type' => 1, 'seal' => 'SELLO-CFDI',
        ]);

        DB::table('charge')->insert([
            'charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 1, 'price' => 100,
        ]);
    }

    /**
     * Livewire solo inyecta en `mount()` los parámetros de la RUTA. El booking y
     * el tipo viajan en la cadena de consulta, así que hay que leerlos de la
     * petición: sin eso, la pantalla de alta respondía 404.
     */
    public function test_la_pantalla_de_alta_abre_con_el_booking_de_la_direccion(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('transactions.create', ['booking' => 1, 'tipo' => 'costo']))
            ->assertOk()
            ->assertSee('Nuevo costo');
    }

    public function test_la_pantalla_de_alta_sin_booking_responde_404(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('transactions.create'))
            ->assertNotFound();
    }
}
