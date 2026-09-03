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

/** Borrar un booking: es físico y no se puede deshacer, así que se cuida. */
class BookingDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1]]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0,
        ]]);
    }

    private function detalle(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs(User::create([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]));

        return Livewire::test(BookingDetail::class, ['booking' => 1]);
    }

    private function factura(int $cancelada = 0): void
    {
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
            'tran_number' => 'F-1', 'tran_date' => '2026-01-15', 'cancelled' => $cancelada,
        ]]);
    }

    public function test_un_booking_sin_facturacion_se_borra(): void
    {
        $this->detalle()->call('delete');

        $this->assertNull(Booking::find(1));
    }

    public function test_un_booking_con_facturacion_viva_no_se_borra(): void
    {
        $this->factura();

        $this->detalle()->call('delete')->assertSee('sin cancelar');

        $this->assertNotNull(Booking::find(1));
    }

    public function test_una_factura_cancelada_no_estorba(): void
    {
        $this->factura(cancelada: 1);

        $this->detalle()->call('delete');

        $this->assertNull(Booking::find(1));
    }

    public function test_quien_no_es_administrador_no_borra(): void
    {
        $this->detalle(User::ROLE_USER)->call('delete')->assertForbidden();

        $this->assertNotNull(Booking::find(1));
    }
}
