<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\ContinuityReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** Reporte de continuidad y captura de hitos. */
class ContinuityReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0,
        ]]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(ContinuityReport::class);
    }

    /** Hay bookings viejos sin continuidad: capturar un hito debe crearla. */
    public function test_capturar_un_hito_crea_la_continuidad_si_no_existe(): void
    {
        $this->assertSame(0, DB::table('booking_continuity')->count());

        $this->pantalla()
            ->call('editMilestone', 1, 'departure', null)
            ->set('value', '2026-03-01')
            ->call('saveMilestone')
            ->assertHasNoErrors();

        $fila = DB::table('booking_continuity')->first();

        $this->assertSame(1, (int) $fila->booking);
        $this->assertStringStartsWith('2026-03-01', $fila->departure);
    }

    public function test_capturar_un_hito_sobre_una_continuidad_existente(): void
    {
        DB::table('booking_continuity')->insert(['cont_id' => 1, 'booking' => 1]);

        $this->pantalla()
            ->call('editMilestone', 1, 'swb', null)
            ->set('value', '2026-03-05')
            ->call('saveMilestone')
            ->assertHasNoErrors();

        $this->assertSame(1, DB::table('booking_continuity')->count());
        $this->assertStringStartsWith('2026-03-05', DB::table('booking_continuity')->value('swb'));
    }

    public function test_se_puede_borrar_la_fecha_de_un_hito(): void
    {
        DB::table('booking_continuity')->insert(['cont_id' => 1, 'booking' => 1, 'departure' => '2026-03-01 00:00:00']);

        $this->pantalla()
            ->call('editMilestone', 1, 'departure', '2026-03-01')
            ->set('value', '')
            ->call('saveMilestone')
            ->assertHasNoErrors();

        $this->assertNull(DB::table('booking_continuity')->value('departure'));
    }

    /** El hito viaja en la petición: solo se aceptan los de la lista. */
    public function test_un_hito_inventado_se_rechaza(): void
    {
        $this->pantalla()->call('editMilestone', 1, 'modified_by', null)->assertNotFound();
    }

    /** Como el `setdate` del original: la fecha planeada la captura cualquier usuario interno. */
    public function test_cualquier_usuario_interno_captura(): void
    {
        $this->pantalla(User::ROLE_USER)
            ->call('editMilestone', 1, 'departure', null)
            ->set('value', '2026-03-01')
            ->call('saveMilestone')
            ->assertHasNoErrors();

        $this->assertStringStartsWith('2026-03-01', DB::table('booking_continuity')->value('departure'));
    }

    /** El original guardaba fecha y hora; la captura vuelve a traer la hora. */
    public function test_la_fecha_se_guarda_con_su_hora(): void
    {
        $this->pantalla()
            ->call('editMilestone', 1, 'departure', null)
            ->assertSet('value', now()->format('Y-m-d').'T00:00')
            ->set('value', '2026-03-01T14:30')
            ->call('saveMilestone')
            ->assertHasNoErrors();

        $this->assertSame('2026-03-01 14:30:00', DB::table('booking_continuity')->value('departure'));
    }

    public function test_al_abrir_una_fecha_guardada_se_ofrece_con_su_hora(): void
    {
        $this->pantalla()
            ->call('editMilestone', 1, 'departure', '2026-03-01 14:30:00')
            ->assertSet('value', '2026-03-01T14:30');
    }

    public function test_el_listado_descarta_borradores(): void
    {
        DB::table('booking')->insert([[
            'booking_id' => 2, 'booking_number' => 'BORRADOR', 'client' => 1, 'mode' => 10, 'is_draft' => 1,
        ]]);

        $filas = $this->pantalla()->viewData('filas');

        $this->assertCount(1, $filas->items());
        $this->assertSame('BK-1', $filas->items()[0]->booking_number);
    }

    public function test_un_booking_con_dos_filas_de_continuidad_sale_una_vez(): void
    {
        DB::table('booking_continuity')->insert([
            ['cont_id' => 1, 'booking' => 1, 'pickup_date' => '2026-03-01 00:00:00'],
            ['cont_id' => 2, 'booking' => 1, 'pickup_date' => '2026-03-02 00:00:00'],
        ]);

        $filas = $this->pantalla()->set('dates', '01/03/2026 - 31/03/2026')->viewData('filas');

        $this->assertSame(1, $filas->total());
    }
}
