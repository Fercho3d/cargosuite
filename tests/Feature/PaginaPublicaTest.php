<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * La página pública.
 *
 * Antes la raíz mandaba directo al login: quien llegaba al dominio no veía nada
 * de lo que hace el sistema.
 */
class PaginaPublicaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        // La portada pública se prueba encendida, sin depender del .env local
        // (que en esta máquina puede estar apagado para replicar a un cliente).
        config(['marca.landing' => true]);
    }

    private function admin(int $rol = User::ROLE_SUPER_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    public function test_la_raiz_enseña_la_pagina_publica(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('Iniciar sesión'))
            ->assertSee(__('Qué incluye'));
    }

    /** Sin portada (instalación de cliente): la raíz va directo al login. */
    public function test_sin_portada_la_raiz_va_al_login(): void
    {
        config(['marca.landing' => false]);

        $this->get('/')->assertRedirect(route('login'));
    }

    /** Quien ya entró no ve la página de venta: se va a lo suyo. */
    public function test_con_sesion_no_se_ve_la_pagina_publica(): void
    {
        $this->actingAs($this->admin())->get('/')->assertRedirect(route('dashboard'));
    }
}
