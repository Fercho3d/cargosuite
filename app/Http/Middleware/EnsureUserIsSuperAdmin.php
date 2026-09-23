<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reserva una sección al super administrador, el dueño del software.
 *
 * Traduce el `matchCallback` con `User::isSuperAdmin()` del `UserController`
 * de Yii2 y la bandera `visible` del menú «Options» original: los usuarios, los
 * ajustes de la instalación y las solicitudes de demostración no son cosa de
 * cualquier administrador.
 */
class EnsureUserIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        abort_unless($usuario && $usuario->isSuperAdmin(), 403, __('Esta sección es solo para el super administrador'));

        return $next($request);
    }
}
