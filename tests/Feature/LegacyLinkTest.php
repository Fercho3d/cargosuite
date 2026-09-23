<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** La barra superior lleva al sistema viejo mientras conviven los dos. */
class LegacyLinkTest extends TestCase
{
    public function test_la_barra_superior_abre_el_sistema_viejo(): void
    {
        CoreSchema::create();
        CoreSchema::createUsers();
        config(['services.legacy.url' => 'http://viejo.test']);

        $usuario = User::forceCreate(['username' => 'operador', 'password' => 'secreto-de-prueba', 'role' => User::ROLE_ADMIN, 'status' => 1]);

        $this->actingAs($usuario)->get(route('dashboard'))
            ->assertSee('href="http://viejo.test"', false);
    }
}
