<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Qué enlaces pinta el menú lateral a cada rol.
 *
 * Un enlace solo se pinta a quien su ruta deja pasar: antes «Clientes y
 * proveedores», «Catálogos» o «Tipos de cambio» salían al operador y le daban
 * 403 al hacer clic. Y lo que en el sistema original era solo del super
 * administrador (usuarios, servicios, tipo de cambio) sigue siéndolo.
 */
class MenuPorRolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();

        // Todo encendido y con dos catálogos, uno de ellos solo del super
        // administrador, para que el enlace de catálogos tenga a dónde ir.
        config([
            'marca.landing' => true,
            'marca.ajustes' => true,
            'marca.catalogos' => 'puertos-descarga,modalidades',
        ]);
    }

    private function menu(int $rol): TestResponse
    {
        $usuario = new User;
        $usuario->usr_id = 42;
        $usuario->role = $rol;
        $usuario->access = User::ACCESS_INTERNAL;
        $usuario->username = 'prueba';

        // La pantalla de seguridad la ve cualquiera con sesión y lleva el menú.
        return $this->actingAs($usuario)->get(route('security.show'))->assertOk();
    }

    /** @return list<string> */
    private function enlaces(string ...$rutas): array
    {
        return array_map(fn (string $ruta) => 'href="'.route($ruta).'"', $rutas);
    }

    public function test_el_operador_solo_ve_la_operacion(): void
    {
        $this->menu(User::ROLE_USER)
            ->assertSee($this->enlaces('dashboard', 'operations.bookings', 'operations.bookings.create', 'operations.continuity'), false)
            ->assertSee('href="'.route('operations.bookings', ['tipo' => 1]).'"', false)
            ->assertSee('href="'.route('operations.bookings', ['tipo' => 2]).'"', false)
            ->assertDontSee($this->enlaces('parties.clients', 'parties.services', 'exchange', 'users', 'settings', 'demo-requests', 'transactions.invoice'), false)
            ->assertDontSee('/catalogos/');
    }

    public function test_el_administrador_ve_terceros_y_catalogos_pero_no_lo_del_dueño(): void
    {
        $this->menu(User::ROLE_ADMIN)
            ->assertSee($this->enlaces('parties.clients', 'transactions.invoice', 'operations.bookings.create'), false)
            // El primer catálogo que ÉL puede abrir: `puertos-descarga` es solo del super administrador.
            ->assertSee('href="'.route('catalogs.show', 'modalidades').'"', false)
            ->assertDontSee($this->enlaces('parties.services', 'exchange', 'users', 'settings', 'demo-requests'), false);
    }

    public function test_el_super_administrador_lo_ve_todo(): void
    {
        $this->menu(User::ROLE_SUPER_ADMIN)
            ->assertSee($this->enlaces('parties.clients', 'parties.services', 'exchange', 'users', 'settings', 'demo-requests', 'transactions.invoice'), false)
            ->assertSee('href="'.route('catalogs.show', 'puertos-descarga').'"', false);
    }
}
