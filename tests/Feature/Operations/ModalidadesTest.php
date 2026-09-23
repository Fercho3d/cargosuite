<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingForm;
use App\Models\User;
use App\Support\Expediente;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Cómo mueve la empresa: marítimo, terrestre, o las dos.
 *
 * Las dos capacidades viven **siempre** en el sistema. Lo que la configuración
 * decide es cuál se enseña, para que una empresa mixta —que subcontrata el
 * barco y tiene camiones propios— vea todo, y una especializada no vea campos
 * que nunca va a llenar.
 */
class ModalidadesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('vessel')->insert([['vessel_id' => 1, 'vessel_name' => 'Buque Uno']]);
        DB::table('loading_ports')->insert([['port_id' => 1, 'port_name' => 'Origen', 'deleted' => 0]]);
        DB::table('dicharge_port')->insert([['dicharge_port_id' => 1, 'name' => 'Destino', 'deleted' => 0]]);
        DB::table('pickup_place')->insert([['pick_id' => 1, 'name' => 'Punto']]);
        DB::table('operador')->insert([['operador_id' => 1, 'nombre' => 'Miguel Ramírez', 'activo' => 1]]);
        DB::table('unidad')->insert([['unidad_id' => 1, 'numero' => 'T-101', 'tipo' => 'tractor', 'activo' => 1]]);
    }

    private function pantalla(): Testable
    {
        $usuario = User::forceCreate([
            'username' => 'jefa'.uniqid(), 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);

        return Livewire::actingAs($usuario)->test(BookingForm::class);
    }

    public function test_maritimo_ensena_el_buque_y_esconde_la_flota(): void
    {
        config(['marca.modalidades' => 'maritimo']);

        $this->pantalla()->assertSee('Buque')->assertDontSee('Operador')->assertDontSee('Tractor');

        $this->assertTrue(Expediente::visible('vesselId'));
        $this->assertFalse(Expediente::visible('operadorId'));
    }

    public function test_terrestre_ensena_la_flota_y_esconde_el_buque(): void
    {
        config(['marca.modalidades' => 'terrestre']);

        $this->pantalla()->assertDontSee('Buque')->assertSee('Operador')->assertSee('Tractor');

        $this->assertFalse(Expediente::visible('vesselId'));
        $this->assertTrue(Expediente::visible('unidadId'));
    }

    /** Una empresa mixta las enciende las dos y ve todo. */
    public function test_las_dos_a_la_vez_ensenan_todo(): void
    {
        config(['marca.modalidades' => 'maritimo,terrestre']);

        $this->pantalla()->assertSee('Buque')->assertSee('Operador')->assertSee('Tractor');
    }

    /**
     * Quedarse sin modalidad dejaría el expediente sin medio de transporte, así
     * que una errata cae a la de origen en vez de romper la pantalla.
     */
    public function test_una_modalidad_inventada_no_deja_el_sistema_sin_transporte(): void
    {
        config(['marca.modalidades' => 'submarino']);

        $this->assertSame(['maritimo'], Expediente::modalidades());
        $this->pantalla()->assertOk();
    }

    /** Dentro de una modalidad encendida, todavía se puede apagar un campo suelto. */
    public function test_se_puede_afinar_dentro_de_la_modalidad(): void
    {
        config(['marca.modalidades' => 'terrestre', 'marca.expediente_ocultos' => 'cajaId']);

        $this->pantalla()->assertSee('Tractor')->assertDontSee('Caja');

        $this->assertTrue(Expediente::visible('unidadId'));
        $this->assertFalse(Expediente::visible('cajaId'));
    }
}
