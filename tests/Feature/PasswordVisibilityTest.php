<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El ojo para ver la contraseña mientras se teclea.
 *
 * Teclear a ciegas una contraseña larga es la primera causa de «no me deja
 * entrar». El interruptor es solo del navegador: cambia el `type` del campo y
 * nada más, el valor no se copia a ningún lado.
 */
class PasswordVisibilityTest extends TestCase
{
    public function test_la_pantalla_de_acceso_trae_el_ojo(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString("x-bind:type=\"visible ? 'text' : 'password'\"", $html);
        $this->assertStringContainsString('x-on:click="visible = !visible"', $html);
    }

    /**
     * Sin JavaScript el campo tiene que seguir siendo de contraseña: si el
     * `type` viviera solo en Alpine, un navegador con el JS caído enseñaría lo
     * que se teclea.
     */
    public function test_sin_javascript_el_campo_sigue_oculto(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*id="password"[^>]*type="password"/', $html);
    }

    /**
     * Ningún campo de contraseña se queda sin el ojo: la comprobación va sobre
     * el código, que es donde se olvidaría al agregar una pantalla nueva.
     */
    public function test_ninguna_pantalla_teclea_la_contrasena_a_ciegas(): void
    {
        $sueltos = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($rii as $archivo) {
            if ($archivo->isDir() || ! str_ends_with($archivo->getFilename(), '.blade.php')) {
                continue;
            }

            // El propio componente sí lleva el `type` estático: es su respaldo.
            if ($archivo->getFilename() === 'password-input.blade.php') {
                continue;
            }

            if (str_contains(file_get_contents($archivo->getPathname()), 'type="password"')) {
                $sueltos[] = $archivo->getFilename();
            }
        }

        $this->assertSame([], $sueltos, 'Estas vistas usan un campo de contraseña sin el componente: '.implode(', ', $sueltos));
    }
}
