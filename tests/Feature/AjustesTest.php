<?php

namespace Tests\Feature;

use App\Livewire\Settings;
use App\Models\User;
use App\Support\Ajustes;
use App\Support\Catalogs\CatalogRegistry;
use App\Support\Expediente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Ajustes de la instalación, desde el sistema.
 *
 * Antes, «esta empresa es terrestre» vivía solo en el `.env`: cambiarlo exigía
 * entrar por SSH al servidor. Para un sistema que se instala en casa de otros
 * eso no sirve — quien lo administra tiene que poder cambiarlo desde su
 * pantalla, y por eso **lo guardado manda sobre el `.env`**.
 */
class AjustesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
    }

    private function usuario(int $rol = User::ROLE_SUPER_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_SUPER_ADMIN): Testable
    {
        return Livewire::actingAs($this->usuario($rol))->test(Settings::class);
    }

    public function test_solo_el_super_administrador_entra(): void
    {
        $this->pantalla(User::ROLE_ADMIN)->assertForbidden();
    }

    public function test_apagados_no_entra_ni_el_super_administrador(): void
    {
        config(['marca.ajustes' => false]);

        $this->pantalla()->assertNotFound();
    }

    public function test_la_pantalla_ofrece_las_dos_formas_de_transporte(): void
    {
        $this->pantalla()->assertSee('Marítima')->assertSee('Terrestre');
    }

    /** Lo elegido se guarda y manda sobre el `.env`. */
    public function test_elegir_terrestre_enciende_la_flota(): void
    {
        config(['marca.modalidades' => 'maritimo']);

        $this->pantalla()->set('modalidades', ['terrestre'])->call('guardar')->assertHasNoErrors();

        $this->assertSame('terrestre', DB::table('configuracion')->where('clave', 'marca.modalidades')->value('valor'));

        // Y en una petición nueva, con el `.env` diciendo otra cosa:
        config(['marca.modalidades' => 'maritimo']);
        Ajustes::aplicar();

        $this->assertSame(['terrestre'], Expediente::modalidades());
        $this->assertTrue(Expediente::visible('operadorId'));
        $this->assertFalse(Expediente::visible('vesselId'));
        $this->assertArrayHasKey('operadores', CatalogRegistry::visibles());
        $this->assertArrayNotHasKey('buques', CatalogRegistry::visibles());
    }

    public function test_se_pueden_elegir_las_dos(): void
    {
        $this->pantalla()->set('modalidades', ['maritimo', 'terrestre'])->call('guardar')->assertHasNoErrors();

        Ajustes::aplicar();

        $this->assertTrue(Expediente::visible('vesselId'));
        $this->assertTrue(Expediente::visible('operadorId'));
    }

    /**
     * Sin ninguna, el expediente se quedaría sin medio de transporte. Se rechaza
     * al guardar en vez de dejar el sistema en un estado imposible.
     */
    public function test_no_se_puede_quedar_sin_forma_de_transporte(): void
    {
        $this->pantalla()->set('modalidades', [])->call('guardar')->assertHasErrors('modalidades');

        $this->assertSame(0, DB::table('configuracion')->where('clave', 'marca.modalidades')->count());
    }

    public function test_el_timbrado_se_apaga_desde_la_pantalla(): void
    {
        config(['timbrado.habilitado' => true]);

        $this->pantalla()->set('timbrado', false)->set('modalidades', ['maritimo'])->call('guardar');

        config(['timbrado.habilitado' => true]);
        Ajustes::aplicar();

        $this->assertFalse(config('timbrado.habilitado'));
    }

    /** Nada fuera de la lista blanca se guarda, venga como venga. */
    public function test_solo_se_guardan_los_ajustes_permitidos(): void
    {
        Ajustes::guardar(['app.key' => 'robada', 'database.default' => 'otra']);

        $this->assertSame(0, DB::table('configuracion')->count());
    }

    /**
     * En una instalación recién clonada la tabla todavía no existe: un ajuste no
     * puede impedir que el sistema levante.
     */
    public function test_sin_la_tabla_el_sistema_arranca_igual(): void
    {
        Schema::drop('configuracion');

        $this->assertSame([], Ajustes::guardados());

        Ajustes::aplicar();

        $this->assertNotEmpty(Expediente::modalidades());
    }
}
