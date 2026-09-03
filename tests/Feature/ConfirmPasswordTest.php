<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Confirmación de contraseña para entrar a una zona segura.
 *
 * Es el paso previo a activar el 2FA, y estaba ROTO: Fortify comprueba la
 * contraseña buscando al usuario por `Fortify::username()`, que en esta
 * aplicación es **`login`** —un campo virtual del formulario de acceso, que
 * admite usuario o correo— y no existe en la tabla. La consulta salía
 * `select * from users where login is null` y respondía 500, así que el 2FA no
 * se podía activar nunca.
 */
class ConfirmPasswordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
    }

    private function usuario(string $clave = 'Secreta2026$'): User
    {
        $usuario = new User;
        $usuario->usr_id = 42;
        $usuario->role = User::ROLE_ADMIN;
        $usuario->username = 'prueba';
        $usuario->password = Hash::make($clave);

        return $usuario;
    }

    public function test_la_pantalla_abre(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertSee(__('Confirma tu contraseña'));
    }

    public function test_con_la_contrasena_correcta_pasa(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('password.confirm'), ['password' => 'Secreta2026$'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    public function test_con_la_contrasena_equivocada_no_pasa(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('password.confirm'), ['password' => 'la-que-no-es'])
            ->assertSessionHasErrors('password');

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    /** Activar el 2FA exige confirmar antes: sin eso, manda a confirmar. */
    public function test_activar_el_2fa_pide_confirmar_primero(): void
    {
        $this->actingAs($this->usuario())
            ->post('/user/two-factor-authentication')
            ->assertRedirect(route('password.confirm'));
    }
}
