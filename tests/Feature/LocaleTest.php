<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreference;
use App\Support\Locale;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Idioma de la interfaz.
 *
 * El español es la lengua base y las llaves de traducción son el propio texto en
 * español, así que lo que no esté traducido se ve en español. Eso hay que
 * comprobarlo: es lo que hace que se pueda traducir por partes sin dejar la
 * pantalla llena de llaves crudas.
 */
class LocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::createUsers();
        CoreSchema::create();
    }

    private function usuario(): User
    {
        return User::create([
            'username' => 'operador', 'name' => 'Ana Ruiz',
            'password' => 'secreto-de-prueba', 'role' => User::ROLE_ADMIN, 'status' => 1,
        ]);
    }

    public function test_se_guarda_en_las_preferencias_y_en_la_cookie(): void
    {
        $usuario = $this->usuario();

        $this->actingAs($usuario)
            ->put(route('preferences.locale'), ['locale' => 'en'])
            ->assertNoContent()
            ->assertPlainCookie(Locale::COOKIE, 'en');

        $this->assertSame(Locale::En, UserPreference::where('usr_id', $usuario->usr_id)->first()->locale);
    }

    public function test_sin_sesion_basta_la_cookie(): void
    {
        $this->put(route('preferences.locale'), ['locale' => 'en'])
            ->assertNoContent()
            ->assertPlainCookie(Locale::COOKIE, 'en');

        $this->assertDatabaseCount('user_preferences', 0);
    }

    /**
     * La regla se prueba directa y no por HTTP: la respuesta de validación de
     * esta ruta rompe el envoltorio de pruebas de Livewire/Laravel («Call to a
     * member function all() on array» al reciclar la sesión). No llega a pasar
     * desde la interfaz —los botones mandan valores fijos—, así que lo que
     * importa es que la regla no acepte cualquier cosa.
     */
    public function test_un_idioma_desconocido_no_se_acepta(): void
    {
        $this->assertNull(Locale::tryFrom('fr'));

        $validador = Validator::make(
            ['locale' => 'fr'],
            ['locale' => ['required', new Enum(Locale::class)]],
        );

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('locale', $validador->errors()->toArray());
    }

    public function test_la_interfaz_sale_en_ingles(): void
    {
        $this->actingAs($this->usuario())
            ->withUnencryptedCookie(Locale::COOKIE, 'en')
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Waiting on you')
            ->assertSee('lang="en"', false);
    }

    public function test_en_espanol_se_ve_en_espanol(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Pendientes')
            ->assertSee('lang="es"', false);
    }

    /** Lo que todavía no se traduce se ve en español, no como una llave cruda. */
    public function test_lo_no_traducido_cae_al_espanol(): void
    {
        app()->setLocale('en');

        $this->assertSame('Panel', __('Panel', [], 'es'));
        $this->assertSame('Dashboard', __('Panel'));
        // Una frase que no está en el diccionario: se ve tal cual, en español.
        $this->assertSame('Una pantalla que todavía no se traduce.', __('Una pantalla que todavía no se traduce.'));
    }

    public function test_la_preferencia_del_usuario_gana_a_la_cookie(): void
    {
        $usuario = $this->usuario();
        UserPreference::create(['usr_id' => $usuario->usr_id, 'locale' => 'en']);

        $this->actingAs($usuario)
            ->withUnencryptedCookie(Locale::COOKIE, 'es')
            ->get(route('dashboard'))
            ->assertSee('Dashboard');
    }

    /**
     * El respaldo tiene que ser el español.
     *
     * Las llaves de traducción son el texto en español, así que con el respaldo
     * en inglés —el valor que trae Laravel de fábrica— **todo lo traducido se
     * vería en inglés aunque se pida en español**: al no encontrar la llave en
     * `es` la busca en el idioma de respaldo y ahí sí está. Costó un rato
     * entenderlo la primera vez.
     */
    public function test_el_idioma_de_respaldo_es_el_espanol(): void
    {
        $this->assertSame('es', config('app.fallback_locale'));

        app()->setLocale('es');

        $this->assertSame('Facturas', __('Facturas'));
    }

    public function test_la_tabla_de_preferencias_guarda_el_idioma(): void
    {
        $this->assertTrue(Schema::hasColumn('user_preferences', 'locale'));
    }
}
