<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingDetail;
use App\Models\User;
use App\Support\Milestones\BookingMilestones;
use App\Support\Milestones\MilestoneCatalog;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Marcar los pasos del expediente desde su propia pantalla.
 *
 * ⚠️ Antes el detalle pintaba las 27 casillas `_chk_date` heredadas con los
 * rótulos escritos a mano —«Zarpe», «SWB», «VGM»— y **de solo lectura**: en una
 * empresa de camiones la lista no decía nada y encima no se podía marcar. Ahora
 * sale del catálogo de hitos, que cada instalación ajusta, y se marca aquí.
 */
class HitosDelExpedienteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'VJ-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0, 'locked' => 0,
        ]]);

        // El esquema de pruebas ya trae el catálogo heredado; aquí se sustituye
        // por uno propio para no depender de qué hitos traiga de fábrica.
        DB::table('hito')->delete();

        DB::table('hito')->insert([
            ['hito_id' => 1, 'clave' => 'asignado', 'etiqueta' => 'Unidad y operador asignados', 'orden' => 10, 'activo' => 1, 'columna_legado' => null],
            ['hito_id' => 2, 'clave' => 'cargado', 'etiqueta' => 'Cargado', 'orden' => 20, 'activo' => 1, 'columna_legado' => 'gated_IN'],
            ['hito_id' => 3, 'clave' => 'jubilado', 'etiqueta' => 'Paso que ya no se usa', 'orden' => 30, 'activo' => 0, 'columna_legado' => null],
        ]);

        MilestoneCatalog::olvida();
    }

    private function admin(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN): Testable
    {
        return Livewire::actingAs($this->admin($rol))->test(BookingDetail::class, ['booking' => 1]);
    }

    /** La lista es la del catálogo, no una escrita a mano en la plantilla. */
    public function test_la_lista_sale_del_catalogo_de_hitos(): void
    {
        $this->pantalla()
            ->assertSee('Unidad y operador asignados')
            ->assertSee('Cargado')
            ->assertDontSee('Paso que ya no se usa')
            ->assertDontSee('Zarpe');
    }

    public function test_un_clic_marca_el_paso_con_la_fecha_de_hoy(): void
    {
        $this->pantalla()->call('marcaHito', 'asignado')->assertHasNoErrors();

        $this->assertSame(
            now()->toDateString(),
            substr(BookingMilestones::de(1)['asignado'] ?? '', 0, 10),
        );
    }

    /** Y otro clic lo quita: se marca por error más veces de las que se cree. */
    public function test_otro_clic_lo_desmarca(): void
    {
        $this->pantalla()->call('marcaHito', 'asignado');
        $this->pantalla()->call('marcaHito', 'asignado');

        $this->assertArrayNotHasKey('asignado', BookingMilestones::de(1));
    }

    public function test_se_puede_poner_otra_fecha(): void
    {
        $this->pantalla()
            ->call('editaHito', 'cargado')
            ->set('hitoFecha', '2026-05-04')
            ->call('guardaHito')
            ->assertHasNoErrors();

        $this->assertSame('2026-05-04', substr(BookingMilestones::de(1)['cargado'] ?? '', 0, 10));
    }

    /**
     * La fecha planeada del hito con columna heredada la sigue escribiendo: de
     * ahí comen todavía los avisos de tareas atrasadas y el sistema con el que
     * convive la instalación original.
     */
    public function test_la_fecha_planeada_del_hito_heredado_espeja_su_columna(): void
    {
        $this->pantalla()->call('editaHito', 'cargado')->set('hitoFecha', '2026-05-04')->call('guardaHito');

        $this->assertSame('2026-05-04', substr((string) DB::table('booking_continuity')->where('booking', 1)->value('gated_IN'), 0, 10));
    }

    /**
     * Marcarlo, en cambio, es cumplimiento: va a `check_list` y no pisa la
     * fecha planeada. El detalle está en `ListaDeVerificacionTest`.
     */
    public function test_marcar_el_hito_heredado_no_toca_la_fecha_planeada(): void
    {
        $this->pantalla()->call('marcaHito', 'cargado')->assertHasNoErrors();

        $this->assertNull(DB::table('booking_continuity')->where('booking', 1)->value('gated_IN'));
        $this->assertNotNull(DB::table('check_list')->where('booking', 1)->value('gated_IN_chk_date'));
    }

    public function test_una_fecha_invalida_no_pasa(): void
    {
        $this->pantalla()
            ->call('editaHito', 'cargado')
            ->set('hitoFecha', 'el martes')
            ->call('guardaHito')
            ->assertHasErrors('hitoFecha');
    }

    /** Un hito apagado no se marca ni escribiendo su clave a mano. */
    public function test_un_hito_apagado_no_se_marca(): void
    {
        $this->pantalla()->call('marcaHito', 'jubilado')->assertStatus(404);
    }

    public function test_un_expediente_cerrado_no_deja_marcar(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['locked' => 1]);

        $this->pantalla()->call('marcaHito', 'asignado')->assertStatus(403);
    }

    /** Marcar y fechar son de cualquier usuario interno, como `check` y `setdate` en el original. */
    public function test_quien_no_es_administrador_marca_y_pone_fecha(): void
    {
        $this->pantalla(User::ROLE_USER)->call('marcaHito', 'asignado')->assertHasNoErrors();
        $this->pantalla(User::ROLE_USER)->call('editaHito', 'cargado')->set('hitoFecha', '2026-05-04T08:00')->call('guardaHito')->assertHasNoErrors();

        $this->assertSame(
            [now()->toDateString(), '2026-05-04 08:00:00'],
            [substr(BookingMilestones::de(1)['asignado'], 0, 10), BookingMilestones::de(1)['cargado']],
        );
    }

    /** Quitar la marca de un hito sin casilla es desmarcar: de administradores. */
    public function test_quien_no_es_administrador_no_desmarca_un_hito_sin_casilla(): void
    {
        $this->pantalla()->call('marcaHito', 'asignado');

        $this->pantalla(User::ROLE_USER)->call('marcaHito', 'asignado')->assertForbidden();

        $this->assertArrayHasKey('asignado', BookingMilestones::de(1));
    }
}
