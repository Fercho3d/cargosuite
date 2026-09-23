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
 * Campos propios del expediente: lo que este negocio pide y el sistema no traía.
 *
 * Es la otra mitad del punto 2: apagar lo que sobra ya se podía, esto es
 * **añadir** lo que falta. Un taller pide «número de serie» y «horas de uso», y
 * eso no se resuelve con una columna nueva por cliente.
 */
class CamposPropiosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('vessel')->insert([['vessel_id' => 1, 'vessel_name' => 'Buque Uno']]);
        DB::table('loading_ports')->insert([['port_id' => 1, 'port_name' => 'Puerto Uno', 'deleted' => 0]]);
        DB::table('dicharge_port')->insert([['dicharge_port_id' => 1, 'name' => 'Destino Uno', 'deleted' => 0]]);
        DB::table('pickup_place')->insert([['pick_id' => 1, 'name' => 'Bodega Uno']]);
    }

    private function campo(array $valores = []): int
    {
        $id = DB::table('campo_expediente')->insertGetId(array_merge([
            'clave' => 'numero_serie', 'etiqueta' => 'Número de serie', 'tipo' => 'text',
            'grupo' => 'Equipo', 'orden' => 10, 'obligatorio' => 0, 'activo' => 1,
        ], $valores));

        Expediente::olvida();

        return $id;
    }

    private function admin(): User
    {
        return User::forceCreate([
            'username' => 'jefa', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'status' => 1,
        ]);
    }

    private function formulario(): Testable
    {
        $this->actingAs($this->admin());

        return Livewire::test(BookingForm::class)
            ->set('bookingNumber', 'BK-9')->set('clientId', '1')->set('vesselId', '1')
            ->set('loadingPort', '1')->set('loadingDate', '2026-05-01')
            ->set('dischargePort', '1')->set('arrivalDate', '2026-05-20')->set('pickupPlace', '1');
    }

    public function test_un_campo_propio_sale_en_el_formulario(): void
    {
        $this->campo();

        $this->formulario()->assertSee('Número de serie')->assertSee('Equipo');
    }

    public function test_se_guarda_como_fila_y_se_recupera_al_editar(): void
    {
        $campo = $this->campo();

        $this->formulario()->set('propios.numero_serie', 'SN-12345')->call('save')->assertHasNoErrors();

        $booking = DB::table('booking')->first();

        $this->assertSame('SN-12345', DB::table('valor_por_expediente')
            ->where('booking', $booking->booking_id)->where('campo_id', $campo)->value('valor'));

        $this->actingAs($this->admin());
        Livewire::test(BookingForm::class, ['booking' => $booking->booking_id])
            ->assertSet('propios.numero_serie', 'SN-12345');
    }

    public function test_un_campo_obligatorio_se_valida(): void
    {
        $this->campo(['obligatorio' => 1]);

        $this->formulario()->call('save')->assertHasErrors('propios.numero_serie');
    }

    public function test_un_desplegable_solo_acepta_sus_opciones(): void
    {
        $this->campo(['clave' => 'estado_equipo', 'etiqueta' => 'Estado', 'tipo' => 'select', 'opciones' => 'Bueno|Regular|Malo']);

        $this->formulario()->set('propios.estado_equipo', 'Inventado')->call('save')
            ->assertHasErrors('propios.estado_equipo');

        $this->formulario()->set('propios.estado_equipo', 'Regular')->call('save')->assertHasNoErrors();
    }

    public function test_un_numero_no_acepta_letras(): void
    {
        $this->campo(['clave' => 'horas_uso', 'etiqueta' => 'Horas de uso', 'tipo' => 'number']);

        $this->formulario()->set('propios.horas_uso', 'muchas')->call('save')
            ->assertHasErrors('propios.horas_uso');
    }

    /**
     * Igual que con los campos de siempre: apagar un campo no borra lo que ya se
     * capturó en él. El dato sigue ahí si alguien lo vuelve a encender.
     */
    public function test_apagar_un_campo_no_borra_lo_capturado(): void
    {
        $campo = $this->campo();

        $this->formulario()->set('propios.numero_serie', 'SN-12345')->call('save')->assertHasNoErrors();

        DB::table('campo_expediente')->where('campo_id', $campo)->update(['activo' => 0]);
        Expediente::olvida();

        $this->assertSame('SN-12345', DB::table('valor_por_expediente')->where('campo_id', $campo)->value('valor'));
        $this->assertCount(0, Expediente::propios());
    }

    /** Sin campos propios definidos, el formulario funciona exactamente igual. */
    public function test_sin_campos_propios_no_cambia_nada(): void
    {
        $this->formulario()->call('save')->assertHasNoErrors();

        $this->assertSame(1, DB::table('booking')->count());
        $this->assertSame(0, DB::table('valor_por_expediente')->count());
    }
}
