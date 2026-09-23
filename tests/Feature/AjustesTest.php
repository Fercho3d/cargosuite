<?php

namespace Tests\Feature;

use App\Livewire\Settings;
use App\Models\User;
use App\Support\Ajustes;
use App\Support\Catalogs\CatalogRegistry;
use App\Support\Expediente;
use App\Support\Marca;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    public function test_un_color_saca_los_seis_tonos_de_la_paleta(): void
    {
        $this->assertSame(
            ['acento_400', 'acento_500', 'acento_600', 'acento_700', 'marca_claro', 'marca_oscuro'],
            array_keys(Marca::paleta('#10b981')),
        );
    }

    public function test_el_tono_700_es_mas_oscuro_que_el_elegido(): void
    {
        $paleta = Marca::paleta('#10b981');

        $this->assertLessThan(hexdec(substr('#10b981', 3, 2)), hexdec(substr($paleta['acento_700'], 3, 2)));
    }

    public function test_el_color_se_cambia_desde_la_pantalla(): void
    {
        $this->pantalla()->set('color', '#7c3aed')->set('modalidades', ['maritimo'])->call('guardar');

        config(['marca.colores.acento_500' => '#000000']);
        Ajustes::aplicar();

        $this->assertStringContainsString('--color-accent-500:#7c3aed;', Marca::estilos());
    }

    public function test_un_color_que_no_es_color_no_se_guarda(): void
    {
        $this->pantalla()->set('color', 'red;}body{display:none')->set('modalidades', ['maritimo'])
            ->call('guardar')->assertHasErrors('color');
    }

    public function test_el_texto_del_logotipo_se_cambia_desde_la_pantalla(): void
    {
        $this->pantalla()->set('logoPrincipal', 'Trans')->set('logoAcento', 'Norte')
            ->set('modalidades', ['maritimo'])->call('guardar');

        $this->assertSame('Norte', config('marca.logo.texto.acento'));
    }

    public function test_el_logotipo_de_imagen_se_sube_desde_la_pantalla(): void
    {
        Storage::fake('public');

        $this->pantalla()->set('logoClaro', UploadedFile::fake()->image('logo.png', 400, 100))
            ->set('modalidades', ['maritimo'])->call('guardar');

        $this->assertTrue(Marca::usaImagen());
    }

    public function test_quitar_la_imagen_vuelve_al_logotipo_de_letra(): void
    {
        config(['marca.logo.imagen.claro' => 'storage/marca/viejo.png']);

        $this->pantalla()->set('quitarLogo', true)->set('modalidades', ['maritimo'])->call('guardar');

        $this->assertFalse(Marca::usaImagen());
    }

    public function test_el_logotipo_no_acepta_svg(): void
    {
        Storage::fake('public');

        $this->pantalla()->set('logoClaro', UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'))
            ->set('modalidades', ['maritimo'])->call('guardar')->assertHasErrors('logoClaro');
    }
}
