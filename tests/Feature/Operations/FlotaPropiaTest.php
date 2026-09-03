<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingForm;
use App\Models\User;
use App\Support\Catalogs\CatalogRegistry;
use App\Support\Expediente;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Flota propia: operadores y unidades.
 *
 * El sistema nació para un agente de carga, que **subcontrata** el transporte y
 * por eso solo sabía de proveedores. Una empresa de camiones mueve con gente y
 * equipo suyos, y eso no tenía dónde vivir.
 */
class FlotaPropiaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        // La flota vive tras la modalidad terrestre: sin encenderla, los campos
        // no se pintan (que es justo lo que se comprueba más abajo).
        config(['marca.modalidades' => 'maritimo,terrestre']);

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('vessel')->insert([['vessel_id' => 1, 'vessel_name' => 'Ruta Uno']]);
        DB::table('loading_ports')->insert([['port_id' => 1, 'port_name' => 'Patio Norte', 'deleted' => 0]]);
        DB::table('dicharge_port')->insert([['dicharge_port_id' => 1, 'name' => 'CDMX', 'deleted' => 0]]);
        DB::table('pickup_place')->insert([['pick_id' => 1, 'name' => 'Bodega']]);

        DB::table('operador')->insert([
            ['operador_id' => 1, 'nombre' => 'Miguel Ramírez', 'licencia' => 'FED-1', 'activo' => 1],
            ['operador_id' => 2, 'nombre' => 'Operador de baja', 'licencia' => 'FED-2', 'activo' => 0],
        ]);
        DB::table('unidad')->insert([
            ['unidad_id' => 1, 'numero' => 'T-101', 'tipo' => 'tractor', 'activo' => 1],
            ['unidad_id' => 2, 'numero' => 'C-201', 'tipo' => 'caja', 'activo' => 1],
            ['unidad_id' => 3, 'numero' => 'T-999', 'tipo' => 'tractor', 'activo' => 0],
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'username' => 'jefa', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    private function formulario(): Testable
    {
        $this->actingAs($this->admin());

        return Livewire::test(BookingForm::class)
            ->set('bookingNumber', 'VJ-9')->set('clientId', '1')->set('vesselId', '1')
            ->set('loadingPort', '1')->set('loadingDate', '2026-05-01')
            ->set('dischargePort', '1')->set('arrivalDate', '2026-05-03')->set('pickupPlace', '1');
    }

    public function test_el_viaje_guarda_su_operador_y_su_equipo(): void
    {
        config(['marca.expediente_ocultos' => '']);

        $this->formulario()
            ->set('operadorId', '1')->set('unidadId', '1')->set('cajaId', '2')
            ->call('save')->assertHasNoErrors();

        $viaje = DB::table('booking')->first();

        $this->assertSame(1, (int) $viaje->operador_id);
        $this->assertSame(1, (int) $viaje->unidad_id);
        $this->assertSame(2, (int) $viaje->caja_id);
    }

    /**
     * Un operador dado de baja o una unidad vendida no deben poder asignarse a
     * un viaje nuevo: siguen en el catálogo por su historia, no para usarse.
     */
    public function test_los_dados_de_baja_no_se_ofrecen(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BookingForm::class)
            ->assertSee('Miguel Ramírez')
            ->assertDontSee('Operador de baja')
            ->assertSee('T-101')
            ->assertDontSee('T-999');
    }

    public function test_un_operador_inventado_se_rechaza(): void
    {
        config(['marca.expediente_ocultos' => '']);

        $this->formulario()->set('operadorId', '99')->call('save')->assertHasErrors('operadorId');
    }

    /**
     * Quien subcontrata el transporte apaga los tres y deja de ver selectores
     * que nunca va a llenar. Es el mismo mecanismo del resto de campos.
     */
    public function test_quien_no_tiene_flota_los_apaga(): void
    {
        config(['marca.expediente_ocultos' => 'operadorId,unidadId,cajaId']);

        $this->actingAs($this->admin());

        Livewire::test(BookingForm::class)
            ->assertDontSee('Flota')
            ->assertDontSee('Tractor');

        $this->assertFalse(Expediente::visible('operadorId'));
    }

    /**
     * En una empresa de camiones el buque **no existe**: el medio es el tractor.
     * Reciclar el catálogo de buques como «camiones» obligaría a mantener la
     * flota en dos lugares, así que el campo se apaga y punto.
     */
    public function test_una_empresa_de_camiones_apaga_el_buque_y_el_viaje_se_guarda(): void
    {
        config(['marca.expediente_ocultos' => 'vesselId']);

        $this->actingAs($this->admin());

        Livewire::test(BookingForm::class)
            ->assertDontSee('Buque')
            ->set('bookingNumber', 'VJ-9')->set('clientId', '1')
            ->set('loadingPort', '1')->set('loadingDate', '2026-05-01')
            ->set('dischargePort', '1')->set('arrivalDate', '2026-05-03')
            ->set('pickupPlace', '1')->set('operadorId', '1')->set('unidadId', '1')
            ->call('save')
            ->assertHasNoErrors();

        $viaje = DB::table('booking')->first();

        $this->assertNull($viaje->vessel, 'Se guardó un buque en una operación que no tiene buques.');
        $this->assertSame(1, (int) $viaje->unidad_id);
    }

    /** Y con el campo encendido sigue siendo obligatorio, como siempre. */
    public function test_donde_si_hay_buques_sigue_siendo_obligatorio(): void
    {
        config(['marca.expediente_ocultos' => '']);

        $this->formulario()->set('vesselId', '')->call('save')->assertHasErrors('vesselId');
    }

    /** Apagado, un alta rápida colada por la petición no crea un buque fantasma. */
    public function test_apagado_no_se_cuela_un_buque_nuevo(): void
    {
        config(['marca.expediente_ocultos' => 'vesselId']);

        $antes = DB::table('vessel')->count();

        $this->actingAs($this->admin());

        Livewire::test(BookingForm::class)
            ->set('bookingNumber', 'VJ-10')->set('clientId', '1')
            ->set('loadingPort', '1')->set('loadingDate', '2026-05-01')
            ->set('dischargePort', '1')->set('arrivalDate', '2026-05-03')
            ->set('pickupPlace', '1')
            ->set('newVessel', 'Buque colado')
            ->call('save')->assertHasNoErrors();

        $this->assertSame($antes, DB::table('vessel')->count());
    }

    public function test_los_catalogos_de_flota_estan_registrados(): void
    {
        $todos = CatalogRegistry::all();

        $this->assertArrayHasKey('operadores', $todos);
        $this->assertArrayHasKey('unidades', $todos);
        $this->assertSame('operador', $todos['operadores']->table);
        $this->assertSame('unidad', $todos['unidades']->table);
    }

    /** Las vigencias son lo que de verdad se opera: sin ellas el catálogo es una lista. */
    public function test_el_catalogo_guarda_las_vigencias(): void
    {
        foreach (['licencia_vence', 'examen_medico_vence'] as $campo) {
            $this->assertContains($campo, array_map(
                fn ($f) => $f->name,
                CatalogRegistry::all()['operadores']->fields,
            ));
        }

        foreach (['seguro_vence', 'verificacion_vence'] as $campo) {
            $this->assertContains($campo, array_map(
                fn ($f) => $f->name,
                CatalogRegistry::all()['unidades']->fields,
            ));
        }
    }
}
