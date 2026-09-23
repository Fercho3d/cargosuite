<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Los códigos de recuperación del 2FA van detrás de la confirmación de
 * contraseña, como en Jetstream: antes se pintaban en la pantalla de seguridad
 * con solo tener la sesión abierta.
 */
class CodigosDeRecuperacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
    }

    private function usuarioCon2fa(): User
    {
        $usuario = new User;
        $usuario->usr_id = 42;
        $usuario->role = User::ROLE_USER;
        $usuario->username = 'prueba';
        $usuario->password = Hash::make('Secreta2026$');
        $usuario->two_factor_secret = encrypt('SECRETO');
        $usuario->two_factor_confirmed_at = now();
        $usuario->two_factor_recovery_codes = encrypt(json_encode(['codigo-uno-1234', 'codigo-dos-5678']));

        return $usuario;
    }

    public function test_la_pantalla_de_seguridad_ya_no_pinta_los_codigos(): void
    {
        $this->actingAs($this->usuarioCon2fa())
            ->get(route('security.show'))
            ->assertOk()
            ->assertSee(__('Ver códigos'))
            ->assertDontSee('codigo-uno-1234');
    }

    public function test_sin_confirmar_la_contrasena_manda_a_confirmarla(): void
    {
        $this->actingAs($this->usuarioCon2fa())
            ->get(route('security.recovery-codes'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_con_la_contrasena_confirmada_los_enseña(): void
    {
        $this->actingAs($this->usuarioCon2fa())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.recovery-codes'))
            ->assertOk()
            ->assertSee('codigo-uno-1234')
            ->assertSee('codigo-dos-5678');
    }
}
