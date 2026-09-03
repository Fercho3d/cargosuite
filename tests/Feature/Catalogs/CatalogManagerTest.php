<?php

namespace Tests\Feature\Catalogs;

use App\Livewire\Catalogs\CatalogManager;
use App\Models\User;
use App\Support\Catalogs\CatalogRegistry;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CatalogSchema;
use Tests\TestCase;

/**
 * Alta, baja y edición de catálogos.
 *
 * Corre sobre tablas levantadas a partir de las propias definiciones, no contra
 * la copia local de `frego`: estas pruebas escriben, y esa base la usan las
 * pruebas de paridad.
 */
class CatalogManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Uno con baja lógica y otro sin ella, para cubrir las dos formas de borrar.
        CatalogSchema::create(CatalogRegistry::find('companias'));
        CatalogSchema::create(CatalogRegistry::find('puertos-carga'));
        CatalogSchema::create(CatalogRegistry::find('buques'));
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        $usuario = new User;
        $usuario->usr_id = 7;
        $usuario->role = $rol;

        return $usuario;
    }

    private function pantalla(string $slug, int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(CatalogManager::class, ['catalog' => $slug]);
    }

    public function test_un_catalogo_inexistente_responde_404(): void
    {
        $this->actingAs($this->usuario());

        // Livewire convierte el 404 del `mount()` en respuesta en vez de dejar
        // salir la excepción, así que se comprueba sobre la respuesta.
        Livewire::test(CatalogManager::class, ['catalog' => 'no-existe'])->assertNotFound();
    }

    public function test_agregar_un_registro(): void
    {
        $this->pantalla('companias')
            ->call('create')
            ->set('form.name', 'FTM')
            ->set('form.business_name', 'Frego Transportaciones Marítimas')
            ->set('form.rfc', 'FTM010101AAA')
            ->set('form.active', true)
            ->call('save')
            ->assertHasNoErrors();

        $fila = DB::table('company')->first();

        $this->assertSame('FTM', $fila->name);
        $this->assertSame(1, (int) $fila->active);
    }

    public function test_los_campos_obligatorios_se_validan(): void
    {
        $this->pantalla('companias')
            ->call('create')
            ->set('form.name', '')
            ->call('save')
            ->assertHasErrors('form.name');

        $this->assertSame(0, DB::table('company')->count());
    }

    public function test_editar_un_registro(): void
    {
        DB::table('company')->insert(['company_id' => 1, 'name' => 'FTA', 'active' => 1]);

        $this->pantalla('companias')
            ->call('edit', 1)
            ->assertSet('form.name', 'FTA')
            ->set('form.name', 'FTA renombrada')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('FTA renombrada', DB::table('company')->where('company_id', 1)->value('name'));
        $this->assertSame(1, DB::table('company')->count(), 'Editar no debe crear un registro nuevo.');
    }

    /** Un catálogo que se referencia desde la operación no se borra: se da de baja. */
    public function test_el_catalogo_con_baja_logica_no_borra_la_fila(): void
    {
        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'Manzanillo', 'deleted' => 0]);

        $pantalla = $this->pantalla('puertos-carga')->call('delete', 1);

        $this->assertSame(1, (int) DB::table('loading_ports')->where('port_id', 1)->value('deleted'));
        $this->assertCount(0, $pantalla->viewData('filas')->items(), 'Lo dado de baja no debe seguir en la lista.');
    }

    public function test_el_catalogo_sin_baja_logica_si_borra(): void
    {
        DB::table('vessel')->insert(['vessel_id' => 1, 'vessel_name' => 'Ever Given']);

        $this->pantalla('buques')->call('delete', 1);

        $this->assertSame(0, DB::table('vessel')->count());
    }

    public function test_la_busqueda_filtra(): void
    {
        DB::table('vessel')->insert([
            ['vessel_id' => 1, 'vessel_name' => 'Ever Given'],
            ['vessel_id' => 2, 'vessel_name' => 'Maersk Alabama'],
        ]);

        $filas = $this->pantalla('buques')->set('search', 'maersk')->viewData('filas');

        $this->assertCount(1, $filas->items());
        $this->assertSame('Maersk Alabama', $filas->items()[0]->vessel_name);
    }

    public function test_quien_no_es_administrador_no_puede_escribir(): void
    {
        DB::table('vessel')->insert(['vessel_id' => 1, 'vessel_name' => 'Ever Given']);

        $this->pantalla('buques', User::ROLE_USER)->call('delete', 1)->assertForbidden();
        $this->pantalla('buques', User::ROLE_USER)->call('create')->assertForbidden();

        $this->assertSame(1, DB::table('vessel')->count());
    }

    public function test_la_baja_logica_no_pierde_el_registro_para_quien_ya_lo_usaba(): void
    {
        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'Manzanillo', 'deleted' => 0]);

        $this->pantalla('puertos-carga')->call('delete', 1);

        // El renglón sigue en la base: los bookings viejos que lo referencian
        // siguen pudiendo mostrar su nombre.
        $this->assertSame('Manzanillo', DB::table('loading_ports')->where('port_id', 1)->value('port_name'));
    }
}
