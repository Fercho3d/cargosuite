<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingForm;
use App\Models\Core\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Alta y edición de bookings.
 *
 * Sobre el esquema de pruebas, porque escribe.
 */
class BookingFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        $this->seedFixture();
    }

    private function seedFixture(): void
    {
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('vessel')->insert([['vessel_id' => 1, 'vessel_name' => 'Ever Given']]);
        DB::table('loading_ports')->insert([['port_id' => 1, 'port_name' => 'Manzanillo', 'deleted' => 0]]);
        DB::table('dicharge_port')->insert([['dicharge_port_id' => 1, 'name' => 'San Antonio', 'deleted' => 0]]);
        DB::table('pickup_place')->insert([['pick_id' => 1, 'name' => 'Planta Norte']]);
        DB::table('final_destination')->insert([['final_destination_id' => 1, 'name' => 'Santiago', 'deleted' => 0]]);
        DB::table('container_types')->insert([['contType_id' => 1, 'container_name' => '40 HC']]);
        DB::table('provider')->insert([
            ['provider_id' => 1, 'fullName' => 'Naviera Uno', 'type_id' => 1],
            ['provider_id' => 2, 'fullName' => 'Transportista Uno', 'type_id' => 2],
        ]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::create([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    /** @param  array<string, mixed>  $campos */
    private function formulario(array $campos = [], ?int $booking = null): Testable
    {
        $this->actingAs($this->usuario());

        $componente = Livewire::test(BookingForm::class, $booking === null ? [] : ['booking' => $booking]);

        // `array_merge` y no `+`: el operador de suma conserva el valor de la
        // IZQUIERDA en las claves repetidas y se comería las sobrescrituras.
        foreach (array_merge($this->datosBase(), $campos) as $campo => $valor) {
            $componente->set($campo, $valor);
        }

        return $componente;
    }

    /** @return array<string, string> */
    private function datosBase(): array
    {
        return [
            'bookingNumber' => 'MEX-001',
            'clientId' => '1',
            'vesselId' => '1',
            'loadingPort' => '1',
            'loadingDate' => '2026-02-01',
            'dischargePort' => '1',
            'arrivalDate' => '2026-02-20',
            'pickupPlace' => '1',
        ];
    }

    public function test_crear_un_booking(): void
    {
        $this->formulario()->call('save')->assertHasNoErrors();

        $booking = Booking::first();

        $this->assertSame('MEX-001', $booking->booking_number);
        $this->assertSame(0, (int) $booking->is_draft, 'Nace como booking real, no como borrador.');
        $this->assertSame(Booking::MODE_BOOKING, (int) $booking->mode);
        $this->assertSame(0, (int) $booking->locked);
    }

    public function test_los_campos_obligatorios_se_validan(): void
    {
        $this->formulario(['bookingNumber' => '', 'clientId' => ''])
            ->call('save')
            ->assertHasErrors(['bookingNumber', 'clientId']);

        $this->assertSame(0, Booking::count());
    }

    /** Un arribo anterior a la carga es un error de dedo, no un embarque. */
    public function test_el_arribo_no_puede_ser_anterior_a_la_carga(): void
    {
        $this->formulario(['arrivalDate' => '2026-01-01'])
            ->call('save')
            ->assertHasErrors('arrivalDate');
    }

    /** Los buques cambian de nombre seguido; capturar uno nuevo no debe frenar el alta. */
    public function test_se_puede_dar_de_alta_un_buque_nuevo_desde_el_formulario(): void
    {
        $this->formulario(['vesselId' => '', 'newVessel' => 'MSC Chiyo'])
            ->call('save')
            ->assertHasNoErrors();

        $creado = DB::table('vessel')->where('vessel_name', 'MSC Chiyo')->first();

        $this->assertNotNull($creado);
        $this->assertSame((int) $creado->vessel_id, (int) Booking::first()->vessel);
    }

    public function test_un_buque_nuevo_que_ya_existe_no_se_duplica(): void
    {
        $this->formulario(['vesselId' => '', 'newVessel' => 'Ever Given'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, DB::table('vessel')->where('vessel_name', 'Ever Given')->count());
    }

    public function test_editar_un_booking(): void
    {
        $this->formulario()->call('save');

        $id = (int) Booking::first()->booking_id;

        $this->formulario(['commodity' => 'Aguacate'], $id)->call('save')->assertHasNoErrors();

        $this->assertSame('Aguacate', Booking::find($id)->commodity);
        $this->assertSame(1, Booking::count(), 'Editar no debe crear otro booking.');
    }

    /** Un booking cerrado ya tiene su facturación fija: no se toca. */
    public function test_un_booking_cerrado_no_se_edita(): void
    {
        $this->formulario()->call('save');

        $id = (int) Booking::first()->booking_id;
        DB::table('booking')->where('booking_id', $id)->update(['locked' => 1]);

        $this->formulario(['commodity' => 'Aguacate'], $id)->call('save')->assertStatus(422);

        $this->assertNull(Booking::find($id)->commodity);
    }

    public function test_quien_no_es_administrador_no_entra(): void
    {
        $this->actingAs($this->usuario(User::ROLE_USER));

        Livewire::test(BookingForm::class)->assertForbidden();
    }
}
