<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * `last_login` se marca al entrar de verdad, no al acertar la contraseña.
 *
 * Con 2FA activo, antes quedaba marcado aunque el código nunca llegara: para el
 * grid de usuarios parecía que alguien había ingresado cuando solo tenía la
 * contraseña.
 */
class UltimoIngresoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        RateLimiter::clear('karina|127.0.0.1');
        Carbon::setTestNow('2026-09-21 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function usuario(bool $con2fa = false): User
    {
        return User::forceCreate([
            'username' => 'karina', 'email' => 'karina@ejemplo.com', 'password' => 'Secreta2026$',
            'role' => User::ROLE_USER, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
            'two_factor_secret' => $con2fa ? encrypt('SECRETO') : null,
            'two_factor_confirmed_at' => $con2fa ? now() : null,
            'two_factor_recovery_codes' => $con2fa ? encrypt(json_encode(['codigo-uno-1234'])) : null,
        ]);
    }

    public function test_sin_2fa_se_marca_al_entrar(): void
    {
        $usuario = $this->usuario();

        $this->post('/login', ['login' => 'karina', 'password' => 'Secreta2026$'])->assertRedirect();

        $this->assertAuthenticatedAs($usuario);
        $this->assertSame('2026-09-21 10:00:00', $usuario->refresh()->last_login?->format('Y-m-d H:i:s'));
    }

    public function test_con_2fa_no_se_marca_hasta_superar_el_codigo(): void
    {
        $usuario = $this->usuario(con2fa: true);

        $this->post('/login', ['login' => 'karina', 'password' => 'Secreta2026$'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertNull($usuario->refresh()->last_login);

        Carbon::setTestNow('2026-09-21 10:05:00');

        $this->post('/two-factor-challenge', ['recovery_code' => 'codigo-uno-1234'])->assertRedirect();

        $this->assertAuthenticatedAs($usuario);
        $this->assertSame('2026-09-21 10:05:00', $usuario->refresh()->last_login?->format('Y-m-d H:i:s'));
    }
}
