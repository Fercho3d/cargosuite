<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El nombre de la cookie de sesión no puede depender de la marca.
 *
 * Laravel lo deriva de `APP_NAME` por omisión. En un sistema de marca
 * configurable eso significa que cambiarle el nombre al producto **renombra la
 * cookie**: todas las sesiones abiertas dejan de valer y el siguiente formulario
 * que alguien envíe responde «419 Page Expired» sin ninguna pista de por qué.
 *
 * Pasó de verdad al renombrar de FregoCargo a CargoSuite.
 */
class SessionCookieTest extends TestCase
{
    public function test_la_cookie_de_sesion_no_lleva_el_nombre_de_la_marca(): void
    {
        $cookie = (string) config('session.cookie');

        foreach (['marca.nombre', 'app.name'] as $llave) {
            $marca = Str::slug((string) config($llave));

            $this->assertStringNotContainsString(
                $marca,
                $cookie,
                "La cookie de sesión («{$cookie}») lleva la marca dentro: cambiarla dejaría a todos fuera con un 419."
            );
        }
    }

    public function test_esta_fijada_por_el_entorno_y_no_derivada(): void
    {
        $this->assertSame(
            env('SESSION_COOKIE'),
            config('session.cookie'),
            'SESSION_COOKIE tiene que ir explícito en el .env, no derivarse de APP_NAME.'
        );
    }
}
