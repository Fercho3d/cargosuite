<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Historial del booking: se lee de las cuatro bitácoras que escribe la base y se
 * enseña como una sola línea de tiempo con lo que cambió en cada movimiento.
 */
class BookingHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([
            ['client_id' => 1, 'fullName' => 'Frialsa'],
            ['client_id' => 2, 'fullName' => 'Lubricantes de América'],
        ]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 2, 'mode' => 10,
        ]]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN)
    {
        $usuario = User::forceCreate([
            'username' => 'operador'.$rol, 'name' => 'Ana Ruiz',
            'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);

        $this->actingAs($usuario);

        return Livewire::test(BookingHistory::class, ['booking' => 1]);
    }

    public function test_ensena_solo_lo_que_cambio_y_con_nombres_no_con_ids(): void
    {
        DB::table('booking_history')->insert([
            [
                'change_type' => 'CREATE', 'change_date' => '2026-01-01 09:00:00', 'booking_id' => 1,
                'booking_number' => 'BK-1', 'client' => 1, 'commodity' => 'Aguacate', 'modified_by' => 1,
            ],
            [
                'change_type' => 'UPDATE', 'change_date' => '2026-01-05 12:30:00', 'booking_id' => 1,
                'booking_number' => 'BK-1', 'client' => 2, 'commodity' => 'Aguacate', 'modified_by' => 1,
            ],
        ]);

        $pantalla = $this->pantalla();

        // El cambio de cliente sale con el nombre de cada catálogo, no con el id.
        $pantalla->assertSee('Cliente')
            ->assertSee('Frialsa')
            ->assertSee('Lubricantes de América')
            // La mercancía no cambió entre los dos movimientos: no se repite.
            ->assertSeeInOrder(['Cambio', 'Cliente'])
            ->assertSee('Ana Ruiz');
    }

    public function test_la_lista_de_verificacion_se_lee_como_casillas_marcadas(): void
    {
        DB::table('check_list_history')->insert([
            [
                'change_type' => 'CREATE', 'change_date' => '2026-01-01 09:00:00',
                'check_id' => 1, 'booking' => 1, 'modified_by' => 1, 'departure_chk_date' => null,
            ],
            [
                'change_type' => 'UPDATE', 'change_date' => '2026-01-02 09:00:00',
                'check_id' => 1, 'booking' => 1, 'modified_by' => 1,
                'departure_chk_date' => '2026-01-02 08:55:00',
            ],
        ]);

        $this->pantalla()
            ->assertSee('Lista de verificación')
            ->assertSee('Zarpe')
            ->assertSee('marcada');
    }

    public function test_cada_contenedor_lleva_su_propia_historia(): void
    {
        DB::table('containers_history')->insert([
            [
                'change_type' => 'CREATE', 'change_date' => '2026-01-01 09:00:00',
                'container_ID' => 7, 'booking' => 1, 'quantity' => 1, 'number' => 'AAA1', 'modified_by' => 1,
            ],
            [
                'change_type' => 'UPDATE', 'change_date' => '2026-01-03 09:00:00',
                'container_ID' => 7, 'booking' => 1, 'quantity' => 2, 'number' => 'AAA1', 'modified_by' => 1,
            ],
            [
                'change_type' => 'CREATE', 'change_date' => '2026-01-02 09:00:00',
                'container_ID' => 8, 'booking' => 1, 'quantity' => 5, 'number' => 'BBB2', 'modified_by' => 1,
            ],
        ]);

        // El contenedor 8 no hereda la cantidad del 7: son series separadas.
        $this->pantalla()
            ->assertSee('Contenedor 7')
            ->assertSee('Contenedor 8')
            ->assertSee('BBB2');
    }

    public function test_se_puede_ver_una_sola_bitacora(): void
    {
        DB::table('booking_history')->insert([[
            'change_type' => 'CREATE', 'change_date' => '2026-01-01 09:00:00', 'booking_id' => 1,
            'booking_number' => 'BK-1', 'modified_by' => 1,
        ]]);
        DB::table('containers_history')->insert([[
            'change_type' => 'CREATE', 'change_date' => '2026-01-02 09:00:00',
            'container_ID' => 7, 'booking' => 1, 'number' => 'AAA1', 'modified_by' => 1,
        ]]);

        $this->pantalla()
            ->set('origen', 'Contenedor')
            ->assertSee('AAA1')
            // La etiqueta del campo solo saldría en un movimiento del booking.
            ->assertDontSee('Número de booking');
    }

    public function test_un_booking_sin_historia_lo_dice(): void
    {
        $this->pantalla()->assertSee('no tiene movimientos registrados');
    }

    /** Como el `history` del original: para cualquier usuario interno. */
    public function test_cualquier_usuario_interno_entra(): void
    {
        $this->pantalla(User::ROLE_USER)->assertOk();
    }

    public function test_la_casilla_marcada_dice_quien_la_marco(): void
    {
        $this->pantalla();
        $marco = User::forceCreate([
            'username' => 'luis', 'name' => 'Luis Pérez',
            'password' => 'secreto-de-prueba', 'role' => User::ROLE_USER, 'status' => 1,
        ]);
        DB::table('check_list_history')->insert([
            [
                'change_type' => 'CREATE', 'change_date' => '2026-01-01 09:00:00', 'check_id' => 1, 'booking' => 1,
                'modified_by' => 1, 'departure_chk_date' => null, 'departure_chk_by' => null,
            ],
            [
                'change_type' => 'UPDATE', 'change_date' => '2026-01-02 09:00:00', 'check_id' => 1, 'booking' => 1,
                'modified_by' => 1, 'departure_chk_date' => '2026-01-02 08:55:00', 'departure_chk_by' => $marco->usr_id,
            ],
        ]);

        Livewire::test(BookingHistory::class, ['booking' => 1])->assertSee('Marcado por: Luis Pérez');
    }

    /** Como las rejillas del original: 100 movimientos por página. */
    public function test_pagina_de_cien_en_cien(): void
    {
        DB::table('booking_history')->insert(collect(range(1, 101))->map(fn (int $i) => [
            'change_type' => 'UPDATE', 'change_date' => now()->subMinutes($i)->toDateTimeString(),
            'booking_id' => 1, 'booking_number' => 'BK-'.$i, 'modified_by' => 1,
        ])->all());

        $eventos = $this->pantalla()->viewData('eventos');

        $this->assertSame([100, 101], [count($eventos->items()), $eventos->total()]);
    }
}
