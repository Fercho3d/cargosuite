<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingDetail;
use App\Livewire\Operations\BookingList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Listado y detalle de bookings.
 *
 * Contra la base real: lo que se comprueba —que el listado descarte borradores y
 * cotizaciones, y que el avance de la lista de verificación cuadre con las
 * casillas marcadas— solo se ve con datos de verdad.
 */
#[Group('parity')]
class BookingListTest extends LegacyDatabaseTestCase
{
    private function usuario(): User
    {
        $usuario = User::query()->where('status', 1)->orderBy('usr_id')->first();

        if (! $usuario) {
            $this->markTestSkipped('La base local no tiene usuarios.');
        }

        return $usuario;
    }

    public function test_el_listado_abre(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('operations.bookings'))
            ->assertOk()
            ->assertSee('Bookings');
    }

    public function test_el_listado_descarta_borradores_y_cotizaciones(): void
    {
        $this->actingAs($this->usuario());

        $filas = Livewire::test(BookingList::class)->viewData('filas');

        $ids = collect($filas->items())->pluck('booking_id');

        $this->assertGreaterThan(0, $ids->count());

        $ajenos = DB::table('booking')
            ->whereIn('booking_id', $ids)
            ->where(fn ($q) => $q->where('is_draft', 1)->orWhere('mode', '<>', 10))
            ->count();

        $this->assertSame(0, $ajenos, 'Se coló un borrador o una cotización.');
    }

    public function test_el_filtro_de_cotizaciones_solo_trae_cotizaciones(): void
    {
        $this->actingAs($this->usuario());

        $filas = Livewire::test(BookingList::class)->set('mode', '9')->viewData('filas');

        if ($filas->total() === 0) {
            $this->markTestSkipped('La base local no tiene cotizaciones.');
        }

        $modos = DB::table('booking')
            ->whereIn('booking_id', collect($filas->items())->pluck('booking_id'))
            ->distinct()
            ->pluck('mode');

        $this->assertSame([9], $modos->map(fn ($m) => (int) $m)->all());
    }

    /**
     * Las 27 casillas que el sistema original cuenta para el avance.
     *
     * Se escriben aquí a propósito, sin leerlas del código, para fijar el
     * comportamiento heredado con sus dos rarezas: la tabla `check_list` tiene
     * **28** columnas de fecha —`pick_up_place` no entra en la cuenta— y el
     * porcentaje se divide entre **26**, no entre 27.
     */
    private const CASILLAS = [
        'booking_number', 'pickup_date', 'modality', 'doc_cut_of', 'SI_date', 'cleared',
        'departure', 'bl_payment', 'swb', 'vessel', 'number', 'client', 'loading_port',
        'loading_EDT', 'dicharge_port', 'container_type', 'commodity', 'set_point',
        'dicharge_ETA', 'vacuum_maneuver', 'draf_client', 'gated_IN', 'gated_out',
        'delivered', 'insurance', 'corrected_draft', 'vgm',
    ];

    /** El avance es cuántas de esas casillas tienen fecha, sobre el divisor histórico. */
    public function test_el_avance_cuadra_con_las_casillas_marcadas(): void
    {
        // El avance heredado es el de la instalación original, y es el que esta
        // prueba compara; el resto del mundo cuenta hitos (`marca.avance`).
        config(['marca.avance' => 'verificacion']);

        $this->actingAs($this->usuario());

        $fila = collect(Livewire::test(BookingList::class)->viewData('filas')->items())
            ->first(fn ($f) => (float) $f->total_completed > 0);

        if ($fila === null) {
            $this->markTestSkipped('Ningún booking de la primera página tiene avance.');
        }

        $casillas = (array) DB::table('check_list')->where('booking', $fila->booking_id)->first();

        $marcadas = collect(self::CASILLAS)
            ->filter(fn (string $casilla) => ($casillas[$casilla.'_chk_date'] ?? null) !== null)
            ->count();

        $this->assertEqualsWithDelta(
            round((100 / 26) * $marcadas, 2),
            (float) $fila->total_completed,
            0.01,
            'El avance no corresponde con las casillas marcadas.',
        );
    }

    public function test_el_detalle_abre_y_enseña_su_numero(): void
    {
        $this->actingAs($this->usuario());

        $booking = DB::table('booking')->where('is_draft', 0)->where('mode', 10)
            ->whereNotNull('booking_number')->orderByDesc('booking_id')->first();

        $this->get(route('operations.bookings.show', $booking->booking_id))
            ->assertOk()
            ->assertSee(trim($booking->booking_number));
    }

    public function test_un_booking_inexistente_responde_404(): void
    {
        $siguiente = (int) DB::table('booking')->max('booking_id') + 1000;

        $this->actingAs($this->usuario());

        Livewire::test(BookingDetail::class, ['booking' => $siguiente])->assertNotFound();
    }

    /**
     * Deja constancia de la rareza: hay una casilla en la tabla que el avance no
     * cuenta. Si algún día se corrige, esta prueba avisa.
     */
    public function test_la_tabla_tiene_una_casilla_que_el_avance_ignora(): void
    {
        $enLaTabla = collect(Schema::connection('frego_legacy')->getColumnListing('check_list'))
            ->filter(fn (string $c) => str_ends_with($c, '_chk_date'))
            ->map(fn (string $c) => substr($c, 0, -strlen('_chk_date')))
            ->values();

        $this->assertCount(28, $enLaTabla, 'Cambió el número de casillas en `check_list`.');
        $this->assertSame(
            ['pick_up_place'],
            $enLaTabla->diff(self::CASILLAS)->values()->all(),
            'La casilla que el avance ignora dejó de ser `pick_up_place`.'
        );
    }
}
