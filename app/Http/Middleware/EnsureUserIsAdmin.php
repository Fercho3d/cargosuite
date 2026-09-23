<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe los módulos internos a administradores.
 *
 * Traduce el `AccessControl` del `TransactionController` de Yii2, que exige
 * `User::isUserAdmin()` en todas sus acciones. Sin esto, cualquier cuenta con
 * sesión —incluidas las de clientes y proveedores— vería la facturación completa.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->isAdmin(), 403, __('Esta sección es solo para administradores.'));

        return $next($request);
    }
}
