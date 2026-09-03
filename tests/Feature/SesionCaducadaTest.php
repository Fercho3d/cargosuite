<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Una pestaña que lleva horas abierta no debe castigarse con una pantalla de
 * error.
 *
 * Laravel responde «419 Page Expired»: una página en blanco con un número y
 * ninguna indicación de qué hacer. Al usuario le parece que el sistema se
 * rompió, y es de las primeras cosas que reporta.
 *
 * ⚠️ Esto se prueba **contra el manejador de excepciones y no por HTTP**: en
 * pruebas, `VerifyCsrfToken` se salta solo (`runningUnitTests()`), así que un
 * POST con token inválido nunca llega a fallar y la prueba pasaría en falso.
 *
 * ⚠️ Y se comprueba con `HttpException(419)` y no con `TokenMismatchException`,
 * porque el manejador de Laravel convierte la segunda en la primera **antes** de
 * consultar los manejadores registrados (`Handler::render()`, líneas 710 y 712).
 * Un manejador tipado a la excepción de sesión no coincide nunca — no falla,
 * simplemente no se entera.
 */
class SesionCaducadaTest extends TestCase
{
    private function respuestaPara(Request $peticion): mixed
    {
        return app(ExceptionHandler::class)->render($peticion, new HttpException(419));
    }

    public function test_un_formulario_caducado_devuelve_a_la_pantalla_con_un_aviso(): void
    {
        $peticion = Request::create('/login', 'POST', ['login' => 'alguien', 'password' => 'secreto']);
        $peticion->setLaravelSession(app('session.store'));

        // `back()` mira la URL anterior de la SESIÓN, no la cabecera `referer`
        // de esta petición: la cabecera solo vale para la petición que el
        // contenedor tiene enlazada, que en una prueba no es esta.
        $peticion->session()->setPreviousUrl(url('/login'));

        $respuesta = $this->respuestaPara($peticion);

        $this->assertInstanceOf(RedirectResponse::class, $respuesta);
        $this->assertSame(url('/login'), $respuesta->getTargetUrl());
        $this->assertSame(
            'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.',
            $respuesta->getSession()?->get('status'),
        );
    }

    /** Lo tecleado se conserva, menos la contraseña. */
    public function test_no_se_devuelve_la_contrasena_al_formulario(): void
    {
        $peticion = Request::create('/login', 'POST', ['login' => 'alguien', 'password' => 'secreto']);
        $peticion->setLaravelSession(app('session.store'));
        $peticion->session()->setPreviousUrl(url('/login'));

        $this->respuestaPara($peticion);

        $viejos = $peticion->session()->get('_old_input', []);

        $this->assertSame('alguien', $viejos['login'] ?? null);
        $this->assertArrayNotHasKey('password', $viejos);
        $this->assertArrayNotHasKey('_token', $viejos);
    }

    /** Quien pide JSON sigue recibiendo el 419, que es lo que espera. */
    public function test_una_peticion_de_api_sigue_recibiendo_el_419(): void
    {
        $peticion = Request::create('/login', 'POST');
        $peticion->headers->set('accept', 'application/json');

        $respuesta = $this->respuestaPara($peticion);

        $this->assertNotInstanceOf(RedirectResponse::class, $respuesta);
        $this->assertSame(419, $respuesta->getStatusCode());
    }

    /** Y un error distinto no se convierte en redirección. */
    public function test_otros_errores_siguen_como_estaban(): void
    {
        $peticion = Request::create('/algo', 'GET');

        $respuesta = app(ExceptionHandler::class)->render($peticion, new HttpException(404));

        $this->assertSame(404, $respuesta->getStatusCode());
    }
}
