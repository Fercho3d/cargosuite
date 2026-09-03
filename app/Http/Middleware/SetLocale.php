<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja la petición en el idioma que eligió quien la hace.
 *
 * Va después de la sesión, porque la preferencia del usuario vive en la base y
 * hace falta saber quién es.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $idioma = Locale::current()->value;

        app()->setLocale($idioma);

        // Las fechas escritas con letra («25 de agosto») las arma Carbon, que
        // lleva su propio idioma: sin esto seguiría en el del arranque.
        Carbon::setLocale($idioma);

        return $next($request);
    }
}
