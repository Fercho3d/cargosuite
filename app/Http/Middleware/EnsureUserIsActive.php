<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saca del sistema a quien fue dado de baja aunque tenga la sesión abierta.
 *
 * El login ya rechaza a las cuentas con `status = 0`, pero una sesión viva o la
 * cookie de «recordarme» seguían valiendo hasta que caducaran. El sistema
 * original lo comprobaba en cada clic (`findIdentity` exigía `status = 1`), y
 * aquí se hace igual: en cada petición del grupo `web`.
 *
 * El aviso viaja en la URL y no en la sesión porque Livewire sigue la
 * redirección por `fetch` antes de recargar la página, y ese primer pintado de
 * la pantalla de acceso se comería el mensaje flash.
 */
class EnsureUserIsActive
{
    public const MOTIVO = 'baja';

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null || $usuario->isActive()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login', ['motivo' => self::MOTIVO]);
    }
}
