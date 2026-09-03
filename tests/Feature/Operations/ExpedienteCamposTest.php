<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingForm;
use App\Livewire\Operations\BookingList;
use App\Models\User;
use App\Support\Expediente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Qué campos del expediente pide cada instalación.
 *
 * A un taller le sobran la naviera, el agente aduanal, el tipo de contenedor y
 * la temperatura. No es estético: son casillas que el operador no sabe llenar.
 */
class ExpedienteCamposTest extends TestCase
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

    private function admin(): User
    {
        return User::create([
            'username' => 'jefa', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'status' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function completo(): array
    {
        return [
            'bookingNumber' => 'BK-9', 'clientId' => '1', 'vesselId' => '1',
            'loadingPort' => '1', 'loadingDate' => '2026-05-01',
            'dischargePort' => '1', 'arrivalDate' => '2026-05-20', 'pickupPlace' => '1',
        ];
    }

    private function formulario(array $valores): Testable
    {
        $this->actingAs($this->admin());

        $pantalla = Livewire::test(BookingForm::class);

        foreach ($valores as $campo => $valor) {
            $pantalla->set($campo, $valor);
        }

        return $pantalla;
    }

    public function test_por_omision_se_piden_todos(): void
    {
        config(['marca.expediente_ocultos' => '']);

        $this->formulario($this->completo())->assertSee('Naviera')->assertSee('Temperatura');
        $this->assertSame([], Expediente::ocultos());
    }

    public function test_los_campos_apagados_desaparecen_del_formulario(): void
    {
        config(['marca.expediente_ocultos' => 'carrierId,brokerId,containerType,setPoint']);

        $this->formulario($this->completo())
            ->assertDontSee('Naviera')
            ->assertDontSee('Agente aduanal')
            ->assertDontSee('Temperatura')
            ->assertSee('Transportista');
    }

    /** Un grupo que se queda sin campos no se pinta: se vería como un error. */
    public function test_el_grupo_vacio_no_se_pinta(): void
    {
        config(['marca.expediente_ocultos' => 'containerType,commodity,setPoint']);

        $this->formulario($this->completo())->assertDontSee('Carga');
    }

    public function test_un_expediente_se_guarda_sin_los_campos_apagados(): void
    {
        config(['marca.expediente_ocultos' => 'carrierId,brokerId,containerType,setPoint,commodity,hb']);

        $this->formulario($this->completo())->call('save')->assertHasNoErrors();

        $booking = DB::table('booking')->first();

        $this->assertSame('BK-9', $booking->booking_number);
        $this->assertNull($booking->carrier_id);
        $this->assertNull($booking->set_point);
    }

    /**
     * Lo importante al apagar un campo en una instalación con historial: lo que
     * ya estaba capturado NO se borra. Si el guardado escribiera nulo en los
     * campos apagados, editar un expediente viejo le vaciaría datos en silencio.
     */
    public function test_apagar_un_campo_no_borra_lo_que_ya_estaba_guardado(): void
    {
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'vessel' => 1,
            'loading_port' => 1, 'loading_EDT' => '2026-05-01', 'dicharge_port_id' => 1,
            'dicharge_ETA' => '2026-05-20', 'pick_up_place_id' => 1, 'mode' => 10, 'is_draft' => 0,
            'commodity' => 'Aguacate', 'set_point' => '-18', 'carrier_id' => null,
        ]]);

        config(['marca.expediente_ocultos' => 'commodity,setPoint']);

        $this->actingAs($this->admin());

        Livewire::test(BookingForm::class, ['booking' => 1])
            ->set('bookingNumber', 'BK-1-EDITADO')
            ->call('save')
            ->assertHasNoErrors();

        $booking = DB::table('booking')->where('booking_id', 1)->first();

        $this->assertSame('BK-1-EDITADO', $booking->booking_number);
        $this->assertSame('Aguacate', $booking->commodity, 'Se borró un dato de un campo apagado.');
        $this->assertSame('-18', $booking->set_point);
    }

    /**
     * Guardián: un campo obligatorio no se apaga aunque lo pidan. Sostienen los
     * listados, los reportes y la generación de facturación.
     */
    public function test_un_campo_obligatorio_no_se_puede_apagar(): void
    {
        config(['marca.expediente_ocultos' => 'clientId,loadingPort,commodity']);

        $this->assertSame(['commodity'], Expediente::ocultos());
        $this->assertTrue(Expediente::visible('clientId'));
    }

    /** Y una errata tampoco apaga nada raro. */
    public function test_un_campo_inventado_se_ignora(): void
    {
        config(['marca.expediente_ocultos' => 'nombreQueNoExiste']);

        $this->assertSame([], Expediente::ocultos());
    }

    /**
     * Un campo apagado tampoco deja un filtro en el listado: buscar por algo que
     * el alta no captura es un filtro que nunca encuentra nada, y quien lo usa no
     * tiene manera de saber por qué.
     */
    public function test_un_campo_apagado_no_deja_su_filtro_en_el_listado(): void
    {
        $this->actingAs($this->admin());

        config(['marca.expediente_ocultos' => '']);
        Livewire::test(BookingList::class)->assertSee('Mercancía');

        config(['marca.expediente_ocultos' => 'commodity']);
        Livewire::test(BookingList::class)->assertDontSee('Mercancía');
    }

    public function test_toda_columna_declarada_existe_en_la_tabla(): void
    {
        $columnas = Schema::getColumnListing('booking');

        foreach (Expediente::COLUMNAS as $propiedad => $columna) {
            $this->assertContains($columna, $columnas, "«{$columna}» ({$propiedad}) no existe en `booking`.");
        }
    }
}
