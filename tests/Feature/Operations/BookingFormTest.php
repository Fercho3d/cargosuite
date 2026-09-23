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
        return User::forceCreate([
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
        $this->assertSame(1, (int) $booking->is_draft, 'Nace como borrador: se confirma en el detalle, ya con contenedores.');
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

    /** Como el original: acepta un arribo anterior a la carga, y hay bookings históricos así. */
    public function test_el_arribo_puede_ser_anterior_a_la_carga(): void
    {
        $this->formulario(['arrivalDate' => '2026-01-01'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-01-01', Booking::first()->dicharge_ETA->toDateString());
    }

    /** El arribo por omisión es la misma fecha de carga, no tres semanas después. */
    public function test_el_arribo_por_omision_es_igual_a_la_carga(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(BookingForm::class)
            ->assertSet('loadingDate', now()->toDateString())
            ->assertSet('arrivalDate', now()->toDateString());
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

    /** Como en el original: `create` era de cualquier usuario interno y `update`, de administradores. */
    public function test_cualquier_usuario_interno_crea_pero_no_edita(): void
    {
        $this->actingAs($this->usuario(User::ROLE_USER));

        $componente = Livewire::test(BookingForm::class)->assertOk();

        foreach ($this->datosBase() as $campo => $valor) {
            $componente->set($campo, $valor);
        }

        $componente->call('save')->assertHasNoErrors();

        $this->assertSame(1, Booking::count());

        Livewire::test(BookingForm::class, ['booking' => (int) Booking::first()->booking_id])->assertForbidden();
    }

    // ------------------------------------------------------------ Tipo

    /** `booking_type` es entero: 1 = importación, 2 = exportación, como en el original. */
    public function test_el_tipo_se_guarda_como_entero(): void
    {
        $this->formulario(['bookingType' => '2'])->call('save')->assertHasNoErrors();

        $this->assertSame(Booking::TYPE_EXPORT, (int) Booking::first()->booking_type);
        $this->assertSame('Exportación', Booking::first()->typeLabel());
    }

    public function test_un_tipo_que_no_existe_se_rechaza(): void
    {
        $this->formulario(['bookingType' => '3'])->call('save')->assertHasErrors('bookingType');
    }

    /** «Nueva importación» / «Nueva exportación» llegan con el tipo elegido. */
    public function test_el_tipo_se_preselecciona_por_la_direccion(): void
    {
        $this->actingAs($this->usuario());

        Livewire::withQueryParams(['tipo' => Booking::TYPE_IMPORT])
            ->test(BookingForm::class)
            ->assertSet('bookingType', '1');
    }

    // ------------------------------------------------------- Cotización

    public function test_se_puede_crear_una_cotizacion(): void
    {
        $this->actingAs($this->usuario());

        $componente = Livewire::withQueryParams(['modo' => 'cotizacion'])->test(BookingForm::class)->assertSet('esCotizacion', true);

        foreach ($this->datosBase() as $campo => $valor) {
            $componente->set($campo, $valor);
        }

        $componente->call('save')->assertHasNoErrors();

        $this->assertTrue(Booking::first()->isQuotation());
    }

    // ----------------------------------------------------------- Copiar

    /** Como el `copy_id` del original: todo menos número, buque y candado. */
    public function test_copiar_llena_el_formulario_salvo_numero_y_buque(): void
    {
        $this->formulario(['commodity' => 'Aguacate', 'bookingType' => '1'])->call('save');
        $origen = Booking::first();
        DB::table('booking')->where('booking_id', $origen->booking_id)->update(['locked' => 1]);

        $this->actingAs($this->usuario());

        Livewire::withQueryParams(['copiar' => $origen->booking_id])
            ->test(BookingForm::class)
            ->assertSet('bookingId', null)
            ->assertSet('locked', false)
            ->assertSet('bookingNumber', '')
            ->assertSet('vesselId', '')
            ->assertSet('clientId', '1')
            ->assertSet('commodity', 'Aguacate')
            ->assertSet('bookingType', '1');
    }

    /** La columna `booking.HB` es `varchar(50)`. */
    public function test_el_hb_no_pasa_de_50_caracteres(): void
    {
        $this->formulario(['hb' => str_repeat('H', 51)])->call('save')->assertHasErrors(['hb' => 'max']);
    }
}
