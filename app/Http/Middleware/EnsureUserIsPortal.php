<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** El portal es solo para cuentas de cliente o proveedor. */
class EnsureUserIsPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->isPortal()) {
            return redirect()->route('dashboard');
        }

        abort_unless($usuario !== null, 403);

        return $next($request);
    }
}
