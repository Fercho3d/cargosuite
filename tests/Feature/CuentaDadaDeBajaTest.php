<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Un usuario dado de baja sale del sistema en su siguiente clic.
 *
 * El login ya rechazaba a las cuentas con `status = 0`, pero una sesión abierta
 * seguía valiendo hasta caducar. El sistema original lo comprobaba en cada
 * petición (`findIdentity` exigía `status = 1`); `EnsureUserIsActive` hace lo
 * mismo en el grupo `web`, también para las peticiones de Livewire.
 */
class CuentaDadaDeBajaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
    }

    private function usuario(): User
    {
        return User::forceCreate([
            'username' => 'karina', 'email' => 'karina@ejemplo.com', 'password' => 'x',
            'role' => User::ROLE_USER, 'status' => 1,
        ]);
    }

    public function test_un_usuario_activo_sigue_entrando(): void
    {
        $this->actingAs($this->usuario())->get(route('security.show'))->assertOk();
    }

    public function test_al_darlo_de_baja_su_siguiente_peticion_lo_manda_al_login(): void
    {
        $usuario = $this->usuario();
        $this->actingAs($usuario);

        $usuario->forceFill(['status' => 0])->save();

        $this->get(route('security.show'))
            ->assertRedirect(route('login', ['motivo' => EnsureUserIsActive::MOTIVO]));

        $this->assertGuest();
    }

    /** Livewire sigue la redirección y recarga la pantalla de acceso; no recibe HTML roto. */
    public function test_tambien_aplica_a_las_peticiones_de_livewire(): void
    {
        $usuario = $this->usuario();
        $this->actingAs($usuario);

        $usuario->forceFill(['status' => 0])->save();

        $this->withHeader('X-Livewire', '1')
            ->postJson(route('default-livewire.update'), [])
            ->assertRedirect(route('login', ['motivo' => EnsureUserIsActive::MOTIVO]));
    }

    public function test_la_pantalla_de_acceso_explica_por_que(): void
    {
        $this->get(route('login', ['motivo' => EnsureUserIsActive::MOTIVO]))
            ->assertOk()
            ->assertSee(__('Tu cuenta está dada de baja.'));
    }

    public function test_sin_motivo_no_sale_el_aviso(): void
    {
        $this->get(route('login'))->assertDontSee(__('Tu cuenta está dada de baja.'));
    }
}
