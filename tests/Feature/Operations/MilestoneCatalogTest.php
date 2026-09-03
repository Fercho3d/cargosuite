<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\ContinuityReport;
use App\Models\User;
use App\Support\Milestones\BookingMilestones;
use App\Support\Milestones\MilestoneCatalog;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Los hitos del expediente son datos, no columnas.
 *
 * Esto es lo que permite vender el sistema a un negocio que no sea de carga
 * marítima: un taller captura los suyos —recepción, diagnóstico, entrega— desde
 * la pantalla de catálogos, sin migración ni una línea de código.
 *
 * Se comprueban las dos mitades: que un hito NUEVO funcione entero sin columna
 * que lo respalde, y que los heredados sigan escribiéndose en su columna de
 * siempre, porque de ahí salen todavía el PDF de confirmación, dos columnas del
 * listado, los avisos y el sistema anterior con el que se convive.
 */
class MilestoneCatalogTest extends TestCase
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

    private function admin(): User
    {
        return User::create([
            'username' => 'jefa', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'status' => 1,
        ]);
    }

    /** Un hito que este negocio inventó: no tiene ni tendrá columna propia. */
    private function hitoNuevo(string $clave = 'diagnostico', string $etiqueta = 'Diagnóstico'): int
    {
        $id = DB::table('hito')->insertGetId([
            'clave' => $clave, 'etiqueta' => $etiqueta, 'orden' => 5,
            'activo' => 1, 'columna_legado' => null,
        ]);

        MilestoneCatalog::olvida();

        return $id;
    }

    public function test_un_hito_nuevo_sale_en_la_rejilla_sin_tocar_el_esquema(): void
    {
        $this->hitoNuevo();

        $this->actingAs($this->admin());

        Livewire::test(ContinuityReport::class)->assertSee('Diagnóstico');
    }

    public function test_un_hito_nuevo_se_captura_y_se_guarda_como_fila(): void
    {
        $this->hitoNuevo();
        $this->actingAs($this->admin());

        Livewire::test(ContinuityReport::class)
            ->call('editMilestone', 1, 'diagnostico', null)
            ->set('value', '2026-04-10')
            ->call('saveMilestone')
            ->assertHasNoErrors();

        $this->assertSame('2026-04-10', substr(BookingMilestones::de(1)['diagnostico'], 0, 10));

        // Y no ha inventado ninguna columna: la tabla vieja sigue intacta.
        $this->assertSame(0, DB::table('booking_continuity')->count());
    }

    /**
     * La otra mitad: un hito heredado SÍ se copia a su columna. Si esto se
     * rompe, el PDF de confirmación y los avisos dejan de ver la fecha sin que
     * nada falle — y las pruebas de paridad se caerían mucho después.
     */
    public function test_un_hito_heredado_se_sigue_escribiendo_en_su_columna(): void
    {
        BookingMilestones::guarda(1, 'departure', '2026-04-11', 7);

        $this->assertStringStartsWith('2026-04-11', (string) DB::table('booking_continuity')->value('departure'));
        $this->assertSame('2026-04-11', substr(BookingMilestones::de(1)['departure'], 0, 10));
    }

    public function test_borrar_la_fecha_la_quita_de_los_dos_sitios(): void
    {
        BookingMilestones::guarda(1, 'departure', '2026-04-11', 7);
        BookingMilestones::guarda(1, 'departure', null, 7);

        $this->assertNull(DB::table('booking_continuity')->value('departure'));
        $this->assertArrayNotHasKey('departure', BookingMilestones::de(1));
    }

    public function test_un_hito_apagado_deja_de_ofrecerse_y_no_se_captura(): void
    {
        DB::table('hito')->where('clave', 'swb')->update(['activo' => 0]);
        MilestoneCatalog::olvida();

        $this->actingAs($this->admin());

        $this->assertArrayNotHasKey('swb', MilestoneCatalog::etiquetas());

        Livewire::test(ContinuityReport::class)
            ->call('editMilestone', 1, 'swb', null)
            ->assertNotFound();
    }

    public function test_el_orden_del_catalogo_manda_en_la_rejilla(): void
    {
        DB::table('hito')->where('clave', 'insurance')->update(['orden' => 1]);
        MilestoneCatalog::olvida();

        $this->assertSame('insurance', array_key_first(MilestoneCatalog::etiquetas()));
    }

    /**
     * La rejilla pinta 25 renglones: las fechas se piden de una vez. Antes eran
     * columnas del propio renglón y salían gratis; ahora hay que vigilarlo.
     */
    public function test_las_fechas_de_varios_expedientes_salen_en_una_consulta(): void
    {
        DB::table('booking')->insert([[
            'booking_id' => 2, 'booking_number' => 'BK-2', 'client' => 1, 'mode' => 10, 'is_draft' => 0,
        ]]);

        BookingMilestones::guarda(1, 'departure', '2026-04-11', 7);
        BookingMilestones::guarda(2, 'swb', '2026-04-12', 7);

        DB::enableQueryLog();
        $fechas = BookingMilestones::deVarios([1, 2]);
        $consultas = collect(DB::getQueryLog())
            ->filter(fn ($c) => str_contains($c['query'], 'hito_por_expediente'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $consultas);
        $this->assertSame('2026-04-11', substr($fechas[1]['departure'], 0, 10));
        $this->assertSame('2026-04-12', substr($fechas[2]['swb'], 0, 10));
    }

    /** El catálogo se recuerda por petición, no entre pruebas. */
    public function test_el_catalogo_no_se_queda_pegado_entre_peticiones(): void
    {
        $this->assertCount(14, MilestoneCatalog::activos());

        $this->hitoNuevo('entrega', 'Entrega final');

        $this->assertCount(15, MilestoneCatalog::activos());
    }
}
