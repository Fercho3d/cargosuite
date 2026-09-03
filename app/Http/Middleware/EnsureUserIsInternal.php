<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja fuera del sistema interno a las cuentas de portal.
 *
 * Un cliente o un proveedor entra con una cuenta ligada a él y solo debe ver lo
 * suyo. Sin esta puerta vería la operación completa de la empresa: todos los
 * bookings, todos los catálogos y quién es cliente de quién.
 */
class EnsureUserIsInternal
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario?->isPortal()) {
            return redirect()->route('portal');
        }

        abort_unless($usuario !== null, 403);

        return $next($request);
    }
}
