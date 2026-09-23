<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingDetail;
use App\Livewire\Operations\BookingList;
use App\Models\Core\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Los filtros nuevos del listado: tipo (importación / exportación) y
 * borradores. Con datos hechos a mano; la paridad contra la base real está en
 * `BookingListTest`.
 */
class BookingListFiltrosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('dicharge_port')->insert([
            ['dicharge_port_id' => 1, 'name' => 'Rotterdam', 'deleted' => 0],
            ['dicharge_port_id' => 2, 'name' => 'Yokohama', 'deleted' => 0],
        ]);
        DB::table('pickup_place')->insert([
            ['pick_id' => 1, 'name' => 'Planta Norte'],
            ['pick_id' => 2, 'name' => 'Planta Sur'],
        ]);
        DB::table('booking')->insert([
            ['booking_id' => 1, 'booking_number' => 'IMP-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0, 'booking_type' => Booking::TYPE_IMPORT, 'created_at' => now(),
                'dicharge_port_id' => 1, 'pick_up_place_id' => 1, 'dicharge_ETA' => '2026-03-10'],
            ['booking_id' => 2, 'booking_number' => 'EXP-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0, 'booking_type' => Booking::TYPE_EXPORT, 'created_at' => now(),
                'dicharge_port_id' => 2, 'pick_up_place_id' => 2, 'dicharge_ETA' => '2026-04-10'],
            ['booking_id' => 3, 'booking_number' => 'BORRADOR-1', 'client' => 1, 'mode' => 10, 'is_draft' => 1, 'booking_type' => Booking::TYPE_EXPORT, 'created_at' => now(),
                'dicharge_port_id' => null, 'pick_up_place_id' => null, 'dicharge_ETA' => null],
        ]);
        DB::table('booking_continuity')->insert([
            ['cont_id' => 1, 'booking' => 1, 'SI_date' => '2026-03-01 00:00:00'],
            ['cont_id' => 2, 'booking' => 2, 'SI_date' => '2026-04-01 00:00:00'],
        ]);
    }

    /** Un booking creado el año pasado, que el filtro por omisión esconde. */
    private function bookingViejo(): void
    {
        DB::table('booking')->insert([
            'booking_id' => 4, 'booking_number' => 'VIEJO-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0,
            'created_at' => now()->subYear(),
        ]);
    }

    private function listado(): Testable
    {
        $this->actingAs(User::forceCreate([
            'username' => 'operadora', 'password' => 'secreto-de-prueba', 'role' => User::ROLE_USER, 'status' => 1,
        ]));

        return Livewire::test(BookingList::class);
    }

    /** @return list<string> */
    private function numeros(Testable $listado): array
    {
        return collect($listado->viewData('filas')->items())->pluck('booking_number')->sort()->values()->all();
    }

    public function test_por_omision_no_salen_los_borradores(): void
    {
        $this->assertSame(['EXP-1', 'IMP-1'], $this->numeros($this->listado()));
    }

    public function test_el_filtro_de_borradores_solo_trae_borradores(): void
    {
        $this->assertSame(['BORRADOR-1'], $this->numeros($this->listado()->set('drafts', '1')));
    }

    public function test_el_filtro_de_tipo_separa_importaciones_de_exportaciones(): void
    {
        $this->assertSame(
            [['IMP-1'], ['EXP-1']],
            [$this->numeros($this->listado()->set('bookingType', '1')), $this->numeros($this->listado()->set('bookingType', '2'))],
        );
    }

    // ------------------------------------------------------ Año en curso

    public function test_por_omision_solo_se_ven_los_creados_este_anio(): void
    {
        $this->bookingViejo();

        $this->assertSame(['EXP-1', 'IMP-1'], $this->numeros($this->listado()->assertSee('Ver todos los años')));
    }

    public function test_ver_todos_los_anios_quita_el_filtro_de_un_clic(): void
    {
        $this->bookingViejo();

        $this->assertSame(['EXP-1', 'IMP-1', 'VIEJO-1'], $this->numeros($this->listado()->set('allYears', '1')->assertSee('Todos los años')));
    }

    /** «Limpiar filtros» deja la pantalla sin rango alguno, tampoco el del año. */
    public function test_limpiar_filtros_deja_la_pantalla_sin_rango_de_fechas(): void
    {
        $this->bookingViejo();

        $this->assertSame(['EXP-1', 'IMP-1', 'VIEJO-1'], $this->numeros($this->listado()->set('bookingType', '1')->call('clearFilters')));
    }

    /** Un rango propio también apaga el del año, como hasta ahora. */
    public function test_un_rango_de_arribo_propio_sustituye_al_del_anio(): void
    {
        $this->bookingViejo();

        $this->assertSame(['EXP-1'], $this->numeros($this->listado()->set('arrivalDates', '01/04/2026 - 30/04/2026')));
    }

    // ------------------------------------------------- Filtros del viejo

    public function test_filtra_por_puerto_de_descarga_y_lugar_de_recoleccion(): void
    {
        $this->assertSame(
            [['IMP-1'], ['EXP-1']],
            [$this->numeros($this->listado()->set('dischargePort', '1')), $this->numeros($this->listado()->set('pickupPlace', '2'))],
        );
    }

    public function test_filtra_por_rango_de_corte_de_instrucciones(): void
    {
        $this->assertSame(['IMP-1'], $this->numeros($this->listado()->set('siDates', '01/03/2026 - 31/03/2026')));
    }

    public function test_el_listado_ensena_id_lugar_de_recoleccion_y_corte_si(): void
    {
        $this->listado()
            ->assertSeeInOrder(['ID', 'Lugar de recolección', 'Corte SI'])
            ->assertSee('Planta Norte')
            ->assertSee('01/03/2026')
            ->assertSee(route('operations.bookings.show', 1).'#lista-de-verificacion');
    }

    /** Generar la factura de un booking cerrado solo daba un error: mejor no ofrecerlo. */
    public function test_un_booking_cerrado_no_ofrece_generar_factura(): void
    {
        DB::table('booking')->where('booking_id', 2)->update(['locked' => 1]);

        $this->actingAs(User::forceCreate([
            'username' => 'jefa', 'password' => 'secreto-de-prueba', 'role' => User::ROLE_ADMIN, 'status' => 1,
        ]));

        Livewire::test(BookingList::class)
            ->assertSee(route('operations.bookings.generate', 1))
            ->assertDontSee(route('operations.bookings.generate', 2));
    }

    public function test_el_listado_ensena_el_tipo_y_los_botones_de_alta(): void
    {
        $this->listado()
            ->assertSeeInOrder(['Importación', 'Exportación'])
            ->assertSee('Nueva importación')
            ->assertSee('Nueva exportación');
    }

    public function test_en_cotizaciones_el_boton_es_de_cotizacion(): void
    {
        $this->listado()->set('mode', '9')->assertSee('Nueva cotización')->assertDontSee('Nueva importación');
    }

    /** Un borrador se abre en el detalle: ahí se le capturan contenedores y se confirma. */
    public function test_el_detalle_abre_un_borrador(): void
    {
        $this->actingAs(User::forceCreate([
            'username' => 'jefa', 'password' => 'secreto-de-prueba', 'role' => User::ROLE_ADMIN, 'status' => 1,
        ]));

        Livewire::test(BookingDetail::class, ['booking' => 3])
            ->assertOk()
            ->assertSee('Este booking es un borrador.')
            ->assertSee('Confirmar booking')
            ->assertSee('Copiar');
    }

    /**
     * Un booking con dos filas de continuidad sale una sola vez y con las
     * fechas de la más reciente (mayor `cont_id`), no mezcladas.
     */
    public function test_dos_filas_de_continuidad_dan_un_solo_renglon_con_la_ultima(): void
    {
        DB::table('booking_continuity')->insert(['cont_id' => 3, 'booking' => 1, 'SI_date' => '2026-03-15 00:00:00']);

        $filas = collect($this->listado()->viewData('filas')->items())->where('booking_id', 1);

        $this->assertSame(['2026-03-15 00:00:00'], $filas->pluck('SI_date')->all());
    }
}
