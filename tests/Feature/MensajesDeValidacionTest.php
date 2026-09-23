<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Los mensajes de validación salen en español y no como la clave cruda.
 *
 * Laravel solo los trae en inglés; sin `lang/es/validation.php` la pantalla
 * enseñaba «validation.required» en cualquier regla sin mensaje propio.
 */
class MensajesDeValidacionTest extends TestCase
{
    public function test_los_mensajes_salen_en_espanol(): void
    {
        app()->setLocale('es');

        $errores = Validator::make(
            ['nombre' => '', 'rfc' => str_repeat('x', 30)],
            ['nombre' => 'required', 'rfc' => 'max:25'],
        )->errors();

        $this->assertSame('El campo nombre es obligatorio.', $errores->first('nombre'));
    }

    public function test_ninguna_regla_sale_como_clave(): void
    {
        app()->setLocale('es');

        $errores = Validator::make(
            ['correo' => 'no-es-correo', 'cantidad' => 'abc', 'rfc' => str_repeat('x', 30)],
            ['correo' => 'email', 'cantidad' => 'integer', 'rfc' => 'max:25'],
        )->errors()->all();

        foreach ($errores as $mensaje) {
            $this->assertStringNotContainsString('validation.', $mensaje);
        }
    }
}
