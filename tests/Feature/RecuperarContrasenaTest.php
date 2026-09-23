<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Recuperación de contraseña: una cuenta dada de baja no vuelve a entrar por
 * aquí, y la respuesta no delata que la cuenta existe ni que está de baja.
 */
class RecuperarContrasenaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        // Tabla propia de Laravel; en la base real la crea su migración.
        Schema::create('password_reset_tokens', function ($tabla) {
            $tabla->string('email')->primary();
            $tabla->string('token');
            $tabla->timestamp('created_at')->nullable();
        });
    }

    private function usuario(int $status): User
    {
        return User::forceCreate([
            'username' => 'karina', 'email' => 'karina@ejemplo.com',
            'password' => 'contrasena-vieja', 'role' => User::ROLE_USER, 'status' => $status,
        ]);
    }

    private function restablecer(User $usuario)
    {
        return $this->post(route('password.update'), [
            'token' => Password::broker()->createToken($usuario),
            'email' => $usuario->email,
            'password' => 'contrasena-nueva-larga',
            'password_confirmation' => 'contrasena-nueva-larga',
        ]);
    }

    public function test_una_cuenta_activa_restablece_su_contrasena(): void
    {
        $usuario = $this->usuario(1);

        $this->restablecer($usuario)->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('contrasena-nueva-larga', $usuario->refresh()->password));
    }

    public function test_una_cuenta_dada_de_baja_no_restablece_su_contrasena(): void
    {
        $usuario = $this->usuario(0);

        $this->restablecer($usuario)->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->assertTrue(Hash::check('contrasena-vieja', $usuario->refresh()->password));
    }

    public function test_una_cuenta_dada_de_baja_no_recibe_el_enlace_pero_la_respuesta_es_la_misma(): void
    {
        Notification::fake();
        $usuario = $this->usuario(0);

        $this->post(route('password.email'), ['email' => $usuario->email])
            ->assertSessionHas('status', __('passwords.sent'));

        Notification::assertNothingSent();
    }

    public function test_una_cuenta_activa_recibe_el_enlace(): void
    {
        Notification::fake();
        $usuario = $this->usuario(1);

        $this->post(route('password.email'), ['email' => $usuario->email]);

        Notification::assertSentTo($usuario, ResetPassword::class);
    }

    /** El framework solo trae estos mensajes en inglés; en español salía la clave. */
    public function test_los_mensajes_del_restablecimiento_estan_en_espanol(): void
    {
        $this->assertNotSame('passwords.sent', __('passwords.sent'));
    }

    /** Tras restablecer, el aviso en la pantalla de acceso sale una sola vez. */
    public function test_el_aviso_de_estado_sale_una_sola_vez_en_el_login(): void
    {
        $html = $this->withSession(['status' => 'Aviso-unico-de-prueba'])->get(route('login'))->getContent();

        $this->assertSame(1, substr_count($html, 'Aviso-unico-de-prueba'));
    }
}
