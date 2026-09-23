<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreference;
use App\Support\Theme;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Preferencia de tema: se guarda por usuario y, sin sesión, por cookie.
 *
 * Levanta a mano las dos tablas que necesita en la conexión de pruebas: `users`
 * es heredada de Yii2 (no hay migración que la cree) y `user_preferences` es
 * nuestra.
 */
class ThemeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('usr_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->tinyInteger('role')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->string('remember_token', 100)->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('modified_at')->nullable();
        });

        Schema::create('user_preferences', function ($table) {
            $table->id();
            $table->unsignedInteger('usr_id')->unique();
            $table->string('theme', 10)->default('system');
            $table->string('locale', 5)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    private function usuario(): User
    {
        return User::forceCreate([
            'name' => 'Prueba',
            'username' => 'usuario.prueba',
            'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN,
            'status' => 1,
        ]);
    }

    public function test_un_visitante_guarda_el_tema_en_una_cookie(): void
    {
        $this->put(route('preferences.theme'), ['theme' => 'dark'])
            ->assertNoContent()
            ->assertPlainCookie(Theme::COOKIE, 'dark');

        $this->assertDatabaseCount('user_preferences', 0);
    }

    public function test_un_usuario_con_sesion_guarda_el_tema_en_sus_preferencias(): void
    {
        $usuario = $this->usuario();

        $this->actingAs($usuario)
            ->put(route('preferences.theme'), ['theme' => 'light'])
            ->assertNoContent();

        $this->assertSame(Theme::Light, $usuario->fresh()->themePreference());
    }

    public function test_cambiar_el_tema_no_duplica_la_fila_de_preferencias(): void
    {
        $usuario = $this->usuario();

        foreach (['dark', 'light', 'system'] as $tema) {
            $this->actingAs($usuario)->put(route('preferences.theme'), ['theme' => $tema]);
        }

        $this->assertDatabaseCount('user_preferences', 1);
        $this->assertSame(Theme::System, $usuario->fresh()->themePreference());
    }

    public function test_un_tema_desconocido_se_rechaza(): void
    {
        $this->put(route('preferences.theme'), ['theme' => 'neon'])
            ->assertSessionHasErrors('theme');

        $this->assertDatabaseCount('user_preferences', 0);
    }

    public function test_sin_preferencia_guardada_el_tema_es_el_del_sistema(): void
    {
        $this->assertSame(Theme::System, $this->usuario()->themePreference());
    }

    public function test_la_preferencia_del_usuario_manda_sobre_la_cookie(): void
    {
        $usuario = $this->usuario();
        UserPreference::create(['usr_id' => $usuario->usr_id, 'theme' => Theme::Dark]);

        $this->actingAs($usuario);
        $this->withUnencryptedCookie(Theme::COOKIE, 'light');

        $this->assertSame(Theme::Dark, $usuario->themePreference());
    }

    public function test_la_pantalla_de_acceso_anuncia_el_tema_de_la_cookie(): void
    {
        $this->withUnencryptedCookie(Theme::COOKIE, 'dark')
            ->get(route('login'))
            ->assertOk()
            ->assertSee('data-theme="dark"', false);
    }

    /**
     * Al navegar con `wire:navigate`, Livewire copia los atributos del `<html>`
     * de la respuesta y borra los que no vengan. Si la clase `dark` la pusiera
     * solo el JavaScript, cada cambio de pantalla devolvería el tema claro.
     */
    public function test_el_html_trae_la_clase_oscura_desde_el_servidor(): void
    {
        $this->withUnencryptedCookie(Theme::COOKIE, 'dark')
            ->get(route('login'))
            ->assertOk()
            ->assertSee('class="h-full dark"', false)
            ->assertSee('style="color-scheme: dark"', false);
    }

    public function test_con_tema_del_sistema_manda_la_cookie_que_escribe_el_navegador(): void
    {
        $this->withUnencryptedCookies([
            Theme::COOKIE => 'system',
            Theme::RESOLVED_COOKIE => 'dark',
        ])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('class="h-full dark"', false)
            ->assertSee('data-theme="system"', false);
    }

    public function test_el_usuario_sin_preferencia_hereda_el_tema_de_la_cookie(): void
    {
        // El panel consulta las cifras del mes, así que necesita las tablas.
        CoreSchema::create();

        // Elegir el tema en la pantalla de acceso y verlo cambiar al entrar
        // sería desconcertante: sin preferencia guardada, manda la cookie.
        $this->actingAs($this->usuario())
            ->withUnencryptedCookie(Theme::COOKIE, 'dark')
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('class="h-full dark"', false);
    }
}
