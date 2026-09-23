<?php

namespace Tests\Feature\Catalogs;

use App\Livewire\Catalogs\CatalogManager;
use App\Models\User;
use App\Support\Catalogs\CatalogRegistry;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Qué catálogos ve cada instalación.
 *
 * El sistema trae diecisiete y buena parte solo tienen sentido en carga
 * marítima. Un taller enseña los suyos y deja fuera navieras, puertos y buques
 * sin que nadie toque el código.
 */
class CatalogVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
    }

    private function admin(): User
    {
        return User::forceCreate([
            'username' => 'jefa', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_SUPER_ADMIN, 'status' => 1,
        ]);
    }

    public function test_sin_configurar_se_ven_todos_los_de_la_modalidad(): void
    {
        // Nómina y taller encendidos: si no, sus catálogos se quedan fuera y la
        // prueba dependería del `.env` de cada máquina.
        config(['marca.catalogos' => '', 'marca.modalidades' => 'maritimo,terrestre', 'marca.nomina' => true, 'marca.taller' => true]);

        $this->assertSame(array_keys(CatalogRegistry::all()), array_keys(CatalogRegistry::visibles()));
    }

    /**
     * Un agente de carga no administra operadores ni unidades, y una empresa de
     * camiones no administra buques. Las dos capacidades están siempre en el
     * código; lo que cambia es cuál se enseña.
     */
    public function test_la_modalidad_decide_que_catalogos_existen(): void
    {
        config(['marca.catalogos' => '']);

        config(['marca.modalidades' => 'maritimo']);
        $soloMar = array_keys(CatalogRegistry::visibles());
        $this->assertContains('buques', $soloMar);
        $this->assertNotContains('operadores', $soloMar);
        $this->assertNotContains('unidades', $soloMar);

        config(['marca.modalidades' => 'terrestre']);
        $soloTierra = array_keys(CatalogRegistry::visibles());
        $this->assertNotContains('buques', $soloTierra);
        $this->assertContains('operadores', $soloTierra);

        config(['marca.modalidades' => 'maritimo,terrestre']);
        $ambas = array_keys(CatalogRegistry::visibles());
        $this->assertContains('buques', $ambas);
        $this->assertContains('operadores', $ambas);
    }

    public function test_la_lista_del_env_recorta_los_visibles(): void
    {
        config(['marca.catalogos' => 'hitos, monedas ,bancos']);

        $this->assertSame(['hitos', 'monedas', 'bancos'], array_keys(CatalogRegistry::visibles()));
    }

    /**
     * `all()` tiene que seguir devolviendo todo: de ahí come el guardián que
     * valida cada definición contra el esquema real, y ocultar un catálogo no
     * puede dejarlo sin vigilar.
     */
    public function test_ocultar_un_catalogo_no_lo_saca_del_registro(): void
    {
        config(['marca.catalogos' => 'monedas']);

        $this->assertCount(1, CatalogRegistry::visibles());
        $this->assertArrayHasKey('navieras', CatalogRegistry::all());
    }

    /** Un catálogo oculto tampoco se alcanza escribiendo la dirección. */
    public function test_un_catalogo_oculto_responde_404(): void
    {
        config(['marca.catalogos' => 'monedas']);

        $this->actingAs($this->admin());

        Livewire::test(CatalogManager::class, ['catalog' => 'navieras'])->assertNotFound();
    }

    public function test_un_catalogo_visible_sigue_abriendo(): void
    {
        config(['marca.catalogos' => 'monedas']);

        $this->actingAs($this->admin());

        Livewire::test(CatalogManager::class, ['catalog' => 'monedas'])->assertOk();
    }

    /**
     * Guardián: un slug mal tecleado en el `.env` no falla, solo hace
     * desaparecer el catálogo del menú. Es la misma lección del vocabulario.
     */
    public function test_todo_slug_configurado_tiene_que_existir(): void
    {
        $configurados = array_filter(array_map('trim', explode(',', (string) config('marca.catalogos'))));

        $this->assertSame(
            [],
            array_values(array_diff($configurados, array_keys(CatalogRegistry::all()))),
            'MARCA_CATALOGOS nombra catálogos que no existen: se quedarían fuera del menú sin avisar.',
        );
    }
}
