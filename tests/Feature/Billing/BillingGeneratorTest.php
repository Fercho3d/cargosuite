<?php

namespace Tests\Feature\Billing;

use App\Livewire\Operations\BillingGenerator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Pantalla de generación automática: qué propone y qué escribe al confirmar.
 *
 * El booking de las pruebas lleva dos contenedores de 40 HC y tres de 20 DC.
 */
class BillingGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        // El alta de una transacción registra el tipo de cambio del día; en las
        // pruebas nunca se sale a la red.
        Http::preventStrayRequests();
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [['datos' => []]]]])]);

        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1],
            ['account_id' => 2, 'account_name' => 'Dólares', 'prefix' => 'USD', 'default' => 0],
        ]);
        DB::table('charge_type')->insert([['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0.16]]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno', 'match_pickup_place' => 0]]);
        DB::table('provider')->insert([
            ['provider_id' => 100, 'fullName' => 'Naviera', 'type_id' => 1],
            ['provider_id' => 200, 'fullName' => 'Transportista', 'type_id' => 2],
        ]);
        DB::table('container_types')->insert([
            ['contType_id' => 1, 'container_name' => '40 HC'],
            ['contType_id' => 2, 'container_name' => '20 DC'],
        ]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0,
            'loading_port' => 10, 'dicharge_port_id' => 20, 'pick_up_place_id' => 30,
            'final_destination_id' => 40, 'carrier_id' => 100, 'transport_id' => 200,
        ]]);
        DB::table('containers')->insert([
            ['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 2],
            ['container_ID' => 2, 'booking' => 1, 'container_type' => 2, 'quantity' => 3],
        ]);
    }

    private function servicio(int $id, array $cambios = []): void
    {
        DB::table('service')->insert([array_merge([
            'service_id' => $id, 'description' => 'Servicio '.$id, 'price' => 100,
            'charge_type_id' => 1, 'account_id' => 1, 'active' => 1, 'auto_include' => 1,
            'price_type' => 1, 'type' => 1, 'client_id' => 1,
            'loading_port_id' => 10, 'dicharge_port_id' => 20, 'final_destination_id' => 40,
            'pickup_place_id' => 30, 'container_type_id' => 1,
        ], $cambios)]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(BillingGenerator::class, ['booking' => 1]);
    }

    // ------------------------------------------------------------- Accesos

    public function test_quien_no_es_administrador_no_entra(): void
    {
        $this->actingAs($this->usuario(User::ROLE_USER));

        Livewire::test(BillingGenerator::class, ['booking' => 1])->assertForbidden();
    }

    public function test_un_booking_cerrado_no_admite_documentos_nuevos(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['locked' => 1]);

        $this->pantalla()->assertStatus(422);
    }

    // ---------------------------------------------------------- Propuesta

    /**
     * La propuesta llega entera y marcada: confirmar sin tocar nada tiene que
     * escribir lo mismo que el sistema anterior.
     */
    public function test_la_propuesta_llega_completa_y_marcada(): void
    {
        $this->servicio(1);
        $this->servicio(2, ['container_type_id' => 2]);

        $this->pantalla()->assertSet('selected', ['invoice:1:1', 'invoice:2:2']);
    }

    /** Un precio caducado se propone igual —el original no miraba fechas—, avisando. */
    public function test_un_precio_fuera_de_vigencia_se_propone_igual(): void
    {
        $this->servicio(1, ['start_date' => '2020-01-01', 'end_date' => '2020-12-31']);

        $this->pantalla()
            ->assertSet('selected', ['invoice:1:1'])
            ->assertSee('Fuera de vigencia')
            ->call('generate');

        $this->assertSame(1, DB::table('charge')->count());
    }

    public function test_cada_divisa_abre_su_propio_documento(): void
    {
        $this->servicio(1);
        $this->servicio(2, ['container_type_id' => 2]);
        $this->servicio(3, ['account_id' => 2, 'container_type_id' => 2]);

        $this->pantalla()->call('generate');

        $facturas = DB::table('transaction')->where('tran_type', 0)->orderBy('transc_id')->get();

        $this->assertCount(2, $facturas);
        $this->assertSame([1, 2], $facturas->pluck('account')->map(fn ($v) => (int) $v)->all());
        // La de pesos lleva los dos primeros conceptos; la de dólares, el tercero.
        $this->assertSame(2, DB::table('charge')->where('transaction', $facturas[0]->transc_id)->count());
        $this->assertSame(1, DB::table('charge')->where('transaction', $facturas[1]->transc_id)->count());
    }

    public function test_lo_que_se_quita_no_se_escribe(): void
    {
        $this->servicio(1);
        $this->servicio(2, ['container_type_id' => 2]);

        $this->pantalla()
            ->set('selected', ['invoice:2:2'])
            ->call('generate');

        $conceptos = DB::table('charge')->get();

        $this->assertCount(1, $conceptos);
        $this->assertSame(2, (int) $conceptos[0]->service_id);
    }

    // ---------------------------------------------------------- Escritura

    public function test_la_factura_toma_folio_y_guarda_sus_conceptos(): void
    {
        $this->servicio(1, ['description' => 'Flete marítimo', 'price' => 1500]);

        $this->pantalla()->call('generate');

        $factura = DB::table('transaction')->first();

        $this->assertSame(0, (int) $factura->tran_type);
        $this->assertSame(1, (int) $factura->customer);
        $this->assertNull($factura->vendor);
        $this->assertSame('F-1', $factura->tran_number);
        $this->assertSame(1, (int) $factura->booking);
        // El esquema real guarda una fecha; SQLite la escribe con hora en ceros.
        $this->assertStringStartsWith(now()->toDateString(), $factura->tran_date);

        $concepto = DB::table('charge')->first();

        $this->assertSame('Flete marítimo - 40 HC', $concepto->description);
        $this->assertSame(2.0, (float) $concepto->quantity);
        $this->assertSame(1500.0, (float) $concepto->price);
        $this->assertSame(1, (int) $concepto->service_id);
        $this->assertSame(1, (int) $concepto->type);
    }

    public function test_los_costos_van_al_proveedor_y_no_llevan_folio(): void
    {
        $this->servicio(1, [
            'client_id' => null, 'provider_id' => 100, 'type' => 2, 'pickup_place_id' => null,
        ]);

        $this->pantalla()->call('generate');

        $costo = DB::table('transaction')->first();

        $this->assertSame(1, (int) $costo->tran_type);
        $this->assertSame(100, (int) $costo->vendor);
        $this->assertNull($costo->customer);
        $this->assertNull($costo->tran_number);
    }

    public function test_el_transportista_recibe_un_costo_por_contenedor(): void
    {
        $this->servicio(1, [
            'client_id' => null, 'provider_id' => 200, 'type' => 2, 'price' => 8000,
            'dicharge_port_id' => null, 'final_destination_id' => null, 'container_type_id' => null,
        ]);

        $this->pantalla()->call('generate');

        $costos = DB::table('transaction')->where('vendor', 200)->get();

        $this->assertCount(5, $costos);
        $this->assertSame(5, DB::table('charge')->count());
        $this->assertSame(1.0, (float) DB::table('charge')->first()->quantity);
    }

    public function test_sin_renglones_marcados_no_se_escribe_nada(): void
    {
        $this->servicio(1);

        $this->pantalla()
            ->set('selected', [])
            ->call('generate')
            ->assertHasErrors('plan');

        $this->assertSame(0, DB::table('transaction')->count());
    }

    /**
     * Los servicios de aduana entran aunque estén dados de baja —el original no
     * los filtraba— así que al menos tiene que verse.
     */
    public function test_se_avisa_de_un_servicio_dado_de_baja(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['custom_brocker_id' => 300]);
        DB::table('provider')->insert([['provider_id' => 300, 'fullName' => 'Agente', 'type_id' => 3]]);
        $this->servicio(1, ['price_type' => 3, 'container_type_id' => null, 'active' => 0]);

        $this->pantalla()->assertSee('Servicio dado de baja');
    }

    public function test_se_avisa_de_las_transacciones_que_el_booking_ya_tiene(): void
    {
        $this->servicio(1);
        DB::table('transaction')->insert([[
            'transc_id' => 7, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
            'tran_number' => 'F-99', 'cancelled' => 0, 'tran_date' => '2026-01-01',
        ]]);

        $this->pantalla()->assertSee('F-99');
    }
}
