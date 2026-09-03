<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** Contenedores de un booking: alta, edición y baja desde el detalle. */
class BookingContainersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('container_types')->insert([['contType_id' => 1, 'container_name' => '40 HC']]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0,
        ]]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::create([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function detalle(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(BookingDetail::class, ['booking' => 1]);
    }

    public function test_agregar_un_contenedor(): void
    {
        $this->detalle()
            ->call('addContainer')
            ->set('containerNumber', 'FFAU6079411')
            ->set('containerSeal', '78460')
            ->set('containerType', '1')
            ->set('containerQuantity', '1')
            ->call('saveContainer')
            ->assertHasNoErrors();

        $contenedor = DB::table('containers')->first();

        $this->assertSame('FFAU6079411', $contenedor->number);
        $this->assertSame(1, (int) $contenedor->booking);
    }

    public function test_la_cantidad_no_puede_ser_cero(): void
    {
        $this->detalle()
            ->call('addContainer')
            ->set('containerQuantity', '0')
            ->call('saveContainer')
            ->assertHasErrors('containerQuantity');

        $this->assertSame(0, DB::table('containers')->count());
    }

    public function test_editar_un_contenedor(): void
    {
        DB::table('containers')->insert([
            'container_ID' => 1, 'booking' => 1, 'number' => 'FFAU6079411', 'quantity' => 1,
        ]);

        $this->detalle()
            ->call('editContainer', 1)
            ->assertSet('containerNumber', 'FFAU6079411')
            ->set('containerNumber', 'MSCU1234567')
            ->call('saveContainer')
            ->assertHasNoErrors();

        $this->assertSame('MSCU1234567', DB::table('containers')->where('container_ID', 1)->value('number'));
        $this->assertSame(1, DB::table('containers')->count());
    }

    public function test_quitar_un_contenedor(): void
    {
        DB::table('containers')->insert(['container_ID' => 1, 'booking' => 1, 'quantity' => 1]);

        $this->detalle()->call('deleteContainer', 1);

        $this->assertSame(0, DB::table('containers')->count());
    }

    /** Un contenedor de otro booking no existe para esta pantalla. */
    public function test_no_se_toca_el_contenedor_de_otro_booking(): void
    {
        DB::table('booking')->insert([[
            'booking_id' => 2, 'booking_number' => 'BK-2', 'client' => 1, 'mode' => 10, 'is_draft' => 0,
        ]]);
        DB::table('containers')->insert(['container_ID' => 9, 'booking' => 2, 'quantity' => 1]);

        $this->detalle()->call('deleteContainer', 9);

        $this->assertSame(1, DB::table('containers')->count(), 'No debe borrar el contenedor de otro booking.');
    }

    public function test_un_booking_cerrado_no_recibe_contenedores(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['locked' => 1]);

        $this->detalle()->call('addContainer')->assertForbidden();
    }

    public function test_quien_no_es_administrador_no_toca_los_contenedores(): void
    {
        DB::table('containers')->insert(['container_ID' => 1, 'booking' => 1, 'quantity' => 1]);

        $this->detalle(User::ROLE_USER)->call('deleteContainer', 1)->assertForbidden();

        $this->assertSame(1, DB::table('containers')->count());
    }

    // ------------------------------------------------------------ Cierre

    public function test_cerrar_un_booking(): void
    {
        $this->detalle()->call('lock');

        $this->assertSame(1, (int) DB::table('booking')->where('booking_id', 1)->value('locked'));
    }

    /** Reabrir permite tocar importes ya conciliados: es del super administrador. */
    public function test_solo_el_super_administrador_reabre(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['locked' => 1]);

        $this->detalle()->call('unlock')->assertForbidden();
        $this->assertSame(1, (int) DB::table('booking')->where('booking_id', 1)->value('locked'));

        $this->detalle(User::ROLE_SUPER_ADMIN)->call('unlock');
        $this->assertSame(0, (int) DB::table('booking')->where('booking_id', 1)->value('locked'));
    }

    public function test_quien_no_es_administrador_no_cierra(): void
    {
        $this->detalle(User::ROLE_USER)->call('lock')->assertForbidden();

        $this->assertSame(0, (int) DB::table('booking')->where('booking_id', 1)->value('locked'));
    }
}
