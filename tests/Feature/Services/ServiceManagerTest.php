<?php

namespace Tests\Feature\Services;

use App\Livewire\Services\ServiceForm;
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
        DB::table('provider')->insert([
            ['provider_id' => 1, 'fullName' => 'Proveedor Uno', 'type_id' => 1],
            ['provider_id' => 2, 'fullName' => 'Transportes Dos', 'type_id' => 2],
            ['provider_id' => 3, 'fullName' => 'Agencia Tres', 'type_id' => 3],
        ]);
        DB::table('loading_ports')->insert([['port_id' => 5, 'port_name' => 'Altamira', 'deleted' => 0]]);
        DB::table('dicharge_port')->insert([['dicharge_port_id' => 7, 'name' => 'Rotterdam', 'deleted' => 0]]);
        DB::table('charge_type')->insert([[
            'charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0.16,
            'tax_retention' => 0, 'non_deductible' => 0, 'deleted' => 0,
        ]]);
    }

    /** Super administrador: en Yii2 el `ServiceController` no dejaba entrar a nadie más. */
    private function usuario(int $rol = User::ROLE_SUPER_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_SUPER_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(ServiceManager::class);
    }

    private function formulario(int $rol = User::ROLE_SUPER_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(ServiceForm::class);
    }

    public function test_un_servicio_de_venta_queda_ligado_al_cliente_y_no_al_proveedor(): void
    {
        $this->formulario()
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
        $this->formulario()
            ->set('type', '2')
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
        $this->formulario()
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
        $this->formulario()
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
        DB::table('container_types')->insert([['contType_id' => 3, 'container_name' => '40 RF']]);

        $this->formulario()
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

    /**
     * La ruta que se pide depende del proveedor, como en `_form.php` de Yii2:
     * todo a la naviera, solo puerto de carga y recolección al transportista,
     * nada al agente aduanal. Lo que no aplica no se enseña ni se guarda.
     */
    public function test_a_un_transportista_solo_se_le_pide_puerto_de_carga_y_recoleccion(): void
    {
        $formulario = $this->formulario()
            ->set('type', '2')
            ->set('form.party_id', '2')
            ->assertSeeHtml('wire:model="form.loading_port_id"')
            ->assertSeeHtml('wire:model="form.pickup_place_id"')
            ->assertDontSeeHtml('wire:model="form.dicharge_port_id"')
            ->assertDontSeeHtml('wire:model="form.container_type_id"');

        $formulario
            ->set('form.description', 'Arrastre')
            ->set('form.price', '300')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.loading_port_id', '5')
            ->set('form.dicharge_port_id', '7')
            ->call('save')
            ->assertHasNoErrors();

        $servicio = Service::first();

        $this->assertSame([5, null], [(int) $servicio->loading_port_id, $servicio->dicharge_port_id]);
    }

    public function test_a_un_agente_aduanal_no_se_le_pide_ruta(): void
    {
        $this->formulario()
            ->set('type', '2')
            ->set('form.party_id', '3')
            ->assertSee(__('Un agente aduanal no lleva ruta.'))
            ->assertDontSeeHtml('wire:model="form.loading_port_id"');
    }

    public function test_a_la_naviera_y_al_cliente_se_les_pide_toda_la_ruta(): void
    {
        $this->formulario()
            ->set('type', '2')
            ->set('form.party_id', '1')
            ->assertSeeHtml('wire:model="form.dicharge_port_id"')
            ->assertSeeHtml('wire:model="form.final_destination_id"');

        $this->formulario()
            ->set('form.party_id', '1')
            ->assertSeeHtml('wire:model="form.dicharge_port_id"')
            ->assertSeeHtml('wire:model="form.container_type_id"');
    }

    public function test_editar_deja_quien_lo_modifico_y_cuando(): void
    {
        DB::table('service')->insert([
            'service_id' => 1, 'description' => 'Flete', 'price' => 850, 'charge_type_id' => 1,
            'client_id' => 1, 'type' => 1, 'active' => 1, 'account_id' => 1,
        ]);
        $this->actingAs($usuario = $this->usuario());

        Livewire::test(ServiceForm::class, ['service' => 1])
            ->set('form.price', '900')
            ->call('save')
            ->assertHasNoErrors();

        $servicio = DB::table('service')->where('service_id', 1)->first();

        $this->assertSame((int) $usuario->usr_id, (int) $servicio->modified_by);
        $this->assertNotNull($servicio->modified_at);
    }

    /** El grid del original: id, tipo de precio, ruta, vigencia y quién lo tocó. */
    public function test_el_listado_muestra_ruta_vigencia_y_auditoria(): void
    {
        $this->actingAs($usuario = $this->usuario());
        DB::table('service')->insert([
            'service_id' => 41, 'description' => 'Flete', 'price' => 850, 'charge_type_id' => 1,
            'client_id' => 1, 'type' => 1, 'active' => 1, 'price_type' => Service::PRICE_BY_CONTAINER,
            'loading_port_id' => 5, 'dicharge_port_id' => 7, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'modified_by' => $usuario->usr_id, 'modified_at' => '2026-03-04 10:00:00',
        ]);

        Livewire::test(ServiceManager::class)
            ->assertSeeInOrder(['41', 'Flete', __('Por contenedor'), 'Altamira → Rotterdam', '01/01/2026 – 31/12/2026', $usuario->username, '04/03/2026']);
    }

    public function test_la_vigencia_no_puede_terminar_antes_de_empezar(): void
    {
        $this->formulario()
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
        $this->formulario()
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
        $this->formulario()
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

    /** Un `tipo` desconocido en la URL no deja la lista vacía: se entiende como sin filtro. */
    public function test_un_tipo_desconocido_lista_venta_y_compra(): void
    {
        DB::table('service')->insert([
            'service_id' => 1, 'description' => 'De venta', 'price' => 1,
            'charge_type_id' => 1, 'client_id' => 1, 'type' => 1, 'active' => 1,
        ]);
        DB::table('service')->insert([
            'service_id' => 2, 'description' => 'De compra', 'price' => 1,
            'charge_type_id' => 1, 'provider_id' => 1, 'type' => 2, 'active' => 1,
        ]);

        $this->actingAs($this->usuario());

        $servicios = Livewire::withQueryParams(['tipo' => '9'])->test(ServiceManager::class)->viewData('servicios');

        $this->assertCount(2, $servicios->items());
    }

    /** Sin vigencia, el esquema heredado guarda `0000-00-00`: no se pinta como fecha. */
    public function test_una_vigencia_en_ceros_no_se_pinta(): void
    {
        DB::table('service')->insert([
            'service_id' => 1, 'description' => 'Flete', 'price' => 1, 'charge_type_id' => 1,
            'client_id' => 1, 'type' => 1, 'active' => 1, 'start_date' => '2026-01-01', 'end_date' => '0000-00-00',
        ]);

        $this->pantalla()->assertSee('01/01/2026 – …')->assertDontSee('-0001');
    }

    /** El combo no ofrece lo dado de baja; mandarlo a mano tampoco pasa. */
    public function test_un_tipo_de_cargo_dado_de_baja_no_se_acepta(): void
    {
        DB::table('charge_type')->insert([[
            'charge_type_id' => 2, 'charge_type_name' => 'Viejo', 'tax_rate' => 0.16,
            'tax_retention' => 0, 'non_deductible' => 0, 'deleted' => 1,
        ]]);

        $this->formulario()
            ->set('form.description', 'Flete')
            ->set('form.price', '850')
            ->set('form.charge_type_id', '2')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1')
            ->call('save')
            ->assertHasErrors(['form.charge_type_id' => 'exists']);
    }

    public function test_el_precio_acepta_separador_de_miles(): void
    {
        $this->formulario()
            ->set('form.description', 'Flete Manzanillo')
            ->set('form.price', '42,500.50')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(42500.50, Service::first()->price);
    }

    public function test_guardar_regresa_a_la_lista_con_su_filtro(): void
    {
        $this->actingAs($this->usuario());

        Livewire::withQueryParams(['tipo' => '2', 'tercero' => '1', 'volver' => '/terceros/servicios?tipo=2&tercero=1'])
            ->test(ServiceForm::class)
            ->assertSet('type', '2')
            ->assertSet('form.party_id', '1')
            ->set('form.description', 'Maniobra')
            ->set('form.price', '300')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->call('save')
            ->assertRedirect('/terceros/servicios?tipo=2&tercero=1');
    }

    public function test_editar_desde_la_lista_lleva_su_filtro(): void
    {
        DB::table('service')->insert([['service_id' => 1, 'type' => 2, 'provider_id' => 1, 'charge_type_id' => 1, 'description' => 'Maniobra', 'price' => 300, 'active' => 1]]);

        $this->pantalla()
            ->set('type', '2')
            ->set('partyId', '1')
            ->assertSeeHtml(e(route('parties.services.edit', [1, 'volver' => '/terceros/servicios?tipo=2&tercero=1'])));
    }

    public function test_quien_no_es_administrador_no_escribe(): void
    {
        $this->formulario(User::ROLE_USER)->assertForbidden();
    }

    /** Como en Yii2: servicios y precios son solo del super administrador. */
    public function test_un_administrador_normal_no_entra_a_servicios(): void
    {
        $this->pantalla(User::ROLE_ADMIN)->assertForbidden();
        $this->formulario(User::ROLE_ADMIN)->assertForbidden();
    }
}
