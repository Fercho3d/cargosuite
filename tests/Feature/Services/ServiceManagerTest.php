<?php

namespace Tests\Feature\Services;

use App\Livewire\Services\ServiceManager;
use App\Models\Core\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Servicios y precios.
 *
 * Lo que importa comprobar: que un servicio pertenezca a un cliente **o** a un
 * proveedor y nunca a los dos, y que el precio 0 se entienda como precio abierto.
 */
class ServiceManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1]]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Proveedor Uno']]);
        DB::table('charge_type')->insert([[
            'charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0.16,
            'tax_retention' => 0, 'non_deductible' => 0, 'deleted' => 0,
        ]]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::create([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(ServiceManager::class);
    }

    public function test_un_servicio_de_venta_queda_ligado_al_cliente_y_no_al_proveedor(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('form.description', 'Flete Manzanillo')
            ->set('form.price', '850')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1')
            ->call('save')
            ->assertHasNoErrors();

        $servicio = Service::first();

        $this->assertSame(1, (int) $servicio->client_id);
        $this->assertNull($servicio->provider_id);
        $this->assertSame(Service::TYPE_CLIENT, (int) $servicio->type);
    }

    public function test_un_servicio_de_compra_queda_ligado_al_proveedor(): void
    {
        $this->pantalla()
            ->set('type', '2')
            ->call('create')
            ->set('form.description', 'Maniobra')
            ->set('form.price', '300')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1')
            ->call('save')
            ->assertHasNoErrors();

        $servicio = Service::first();

        $this->assertSame(1, (int) $servicio->provider_id);
        $this->assertNull($servicio->client_id);
        $this->assertSame(Service::TYPE_PROVIDER, (int) $servicio->type);
    }

    public function test_el_precio_cero_se_permite_porque_es_precio_abierto(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('form.description', 'Maniobra variable')
            ->set('form.price', '0')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0.0, Service::first()->price);
    }

    /**
     * Sin tipo de precio, la generación automática no sabría por cuánto
     * multiplicar y escribiría el concepto con cantidad 0. El original lo dejaba
     * pasar; aquí se exige en cuanto el servicio se marca como auto-incluible.
     */
    public function test_un_servicio_auto_incluible_exige_tipo_de_precio(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('form.description', 'Flete')
            ->set('form.price', '850')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1')
            ->set('form.auto_include', true)
            ->call('save')
            ->assertHasErrors('form.price_type');

        $this->assertSame(0, Service::count());
    }

    public function test_se_guardan_la_ruta_y_la_vigencia_del_servicio(): void
    {
        DB::table('loading_ports')->insert([['port_id' => 5, 'port_name' => 'Altamira', 'deleted' => 0]]);
        DB::table('container_types')->insert([['contType_id' => 3, 'container_name' => '40 RF']]);

        $this->pantalla()
            ->call('create')
            ->set('form.description', 'Flete Altamira')
            ->set('form.price', '850')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1')
            ->set('form.auto_include', true)
            ->set('form.price_type', (string) Service::PRICE_BY_CONTAINER)
            ->set('form.loading_port_id', '5')
            ->set('form.container_type_id', '3')
            ->set('form.start_date', '2026-01-01')
            ->set('form.end_date', '2026-12-31')
            ->call('save')
            ->assertHasNoErrors();

        $servicio = Service::first();

        $this->assertSame(1, (int) $servicio->auto_include);
        $this->assertSame(5, (int) $servicio->loading_port_id);
        $this->assertSame(3, (int) $servicio->container_type_id);
        // Los campos vacíos quedan nulos: así empatan con bookings que tampoco
        // tienen ese dato, que es como los compara el emparejador.
        $this->assertNull($servicio->dicharge_port_id);
        $this->assertStringStartsWith('2026-12-31', (string) $servicio->end_date);
    }

    public function test_la_vigencia_no_puede_terminar_antes_de_empezar(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('form.description', 'Flete')
            ->set('form.price', '850')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1')
            ->set('form.start_date', '2026-06-01')
            ->set('form.end_date', '2026-01-01')
            ->call('save')
            ->assertHasErrors('form.end_date');
    }

    public function test_el_precio_no_puede_ser_negativo(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('form.description', 'Servicio')
            ->set('form.price', '-10')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1')
            ->call('save')
            ->assertHasErrors('form.price');
    }

    public function test_el_tipo_de_cargo_es_obligatorio_porque_decide_el_iva(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('form.description', 'Servicio')
            ->set('form.price', '100')
            ->set('form.party_id', '1')
            ->call('save')
            ->assertHasErrors('form.charge_type_id');
    }

    /** No se borran: hay conceptos históricos que los referencian. */
    public function test_desactivar_en_vez_de_borrar(): void
    {
        DB::table('service')->insert([
            'service_id' => 1, 'description' => 'Flete', 'price' => 850, 'charge_type_id' => 1,
            'client_id' => 1, 'type' => 1, 'active' => 1,
        ]);

        $this->pantalla()->call('toggleActive', 1);

        $this->assertSame(0, (int) Service::find(1)->active);
        $this->assertSame(1, Service::count());
    }

    public function test_el_listado_separa_venta_de_compra(): void
    {
        // Dos inserciones y no una con dos filas: en un `insert` múltiple Laravel
        // usa las claves de la PRIMERA fila para todas, y aquí difieren
        // (`client_id` contra `provider_id`), así que la segunda quedaría corrida.
        DB::table('service')->insert([
            'service_id' => 1, 'description' => 'De venta', 'price' => 1,
            'charge_type_id' => 1, 'client_id' => 1, 'type' => 1, 'active' => 1,
        ]);
        DB::table('service')->insert([
            'service_id' => 2, 'description' => 'De compra', 'price' => 1,
            'charge_type_id' => 1, 'provider_id' => 1, 'type' => 2, 'active' => 1,
        ]);

        $venta = $this->pantalla()->viewData('servicios');
        $this->assertCount(1, $venta->items());
        $this->assertSame('De venta', $venta->items()[0]->description);

        $compra = $this->pantalla()->set('type', '2')->viewData('servicios');
        $this->assertCount(1, $compra->items());
        $this->assertSame('De compra', $compra->items()[0]->description);
    }

    public function test_quien_no_es_administrador_no_escribe(): void
    {
        $this->pantalla(User::ROLE_USER)->call('create')->assertForbidden();
    }
}
