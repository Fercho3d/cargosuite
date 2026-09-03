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
        return User::create([
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

    public function test_quien_no_es_administrador_no_captura(): void
    {
        $this->pantalla(User::ROLE_USER)->call('editMilestone', 1, 'departure', null)->assertForbidden();
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
}
