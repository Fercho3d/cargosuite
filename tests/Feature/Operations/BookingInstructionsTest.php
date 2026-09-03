<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingDetail;
use App\Models\Core\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Instrucciones de embarque: los ocho campos del «SI», que se guardan aparte del
 * resto del booking igual que en el original.
 */
class BookingInstructionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0, 'commodity' => 'Aguacate',
        ]]);
    }

    private function detalle(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs(User::create([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]));

        return Livewire::test(BookingDetail::class, ['booking' => 1]);
    }

    public function test_se_capturan_y_se_guardan(): void
    {
        $this->detalle()
            ->call('editInstructions')
            ->set('instructions.shipper_is', 'FRIALSA SA DE CV')
            ->set('instructions.shipper_should', 'FRIALSA FRIGORIFICOS SA DE CV')
            ->call('saveInstructions')
            ->assertHasNoErrors();

        $booking = Booking::find(1);

        $this->assertSame('FRIALSA SA DE CV', $booking->shipper_is);
        $this->assertSame('FRIALSA FRIGORIFICOS SA DE CV', $booking->shipper_should);
        // No toca nada más del booking.
        $this->assertSame('Aguacate', $booking->commodity);
    }

    public function test_no_pasan_de_mil_caracteres(): void
    {
        $this->detalle()
            ->call('editInstructions')
            ->set('instructions.description_is', str_repeat('a', 1001))
            ->call('saveInstructions')
            ->assertHasErrors('instructions.description_is');
    }

    public function test_un_booking_cerrado_no_las_deja_tocar(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['locked' => 1]);

        // Mismo candado que los contenedores de un booking cerrado.
        $this->detalle()->call('editInstructions')->assertForbidden();
    }

    public function test_quien_no_es_administrador_no_las_edita(): void
    {
        $this->detalle(User::ROLE_USER)->call('editInstructions')->assertForbidden();
    }

    public function test_se_ven_sin_editar(): void
    {
        DB::table('booking')->where('booking_id', 1)->update([
            'consignee_is' => 'TO ORDER', 'consignee_should' => 'TO ORDER OF BANK',
        ]);

        $this->detalle()
            ->assertSee('Consignee')
            ->assertSee('TO ORDER OF BANK')
            ->assertSee('Como debe decir');
    }
}
