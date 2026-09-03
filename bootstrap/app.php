<?php

use App\Http\Middleware\SetLocale;
use App\Support\Locale;
use App\Support\Theme;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Las cookies del tema y del idioma no llevan datos sensibles, y una de
        // ellas la escribe el propio navegador, así que van sin cifrar.
        $middleware->encryptCookies(except: [Theme::COOKIE, Theme::RESOLVED_COOKIE, Locale::COOKIE]);

        // El idioma se resuelve después de la sesión: la preferencia del usuario
        // vive en la base y hace falta saber quién entra.
        $middleware->web(append: [SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * Una pestaña que lleva horas abierta manda un formulario con un token
         * caducado y Laravel responde «419 Page Expired»: una pantalla en blanco
         * con un número, sin decir qué hacer. Al usuario le parece que el
         * sistema se rompió, y es de las primeras cosas que reporta.
         *
         * Se le devuelve a la misma pantalla con el formulario relleno —menos la
         * contraseña— y un aviso de que lo vuelva a intentar.
         *
         * ⚠️ Se engancha a la excepción HTTP con estado 419 y **no** a
         * `TokenMismatchException`, que es la que uno esperaría: el manejador de
         * Laravel llama a `prepareException()` ANTES de consultar estos
         * manejadores (`Handler::render()`, líneas 710 y 712), así que para
         * cuando corren, la excepción de sesión ya se convirtió en un
         * `HttpException` de 419 y un manejador tipado a la original no coincide
         * nunca. No falla: simplemente no se entera, y sigue saliendo el 419.
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            return redirect()
                ->back()
                ->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->with('status', __('La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.'));
        });
    })->create();
