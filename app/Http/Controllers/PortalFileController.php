<?php

namespace App\Http\Controllers;

use App\Models\Core\Transaction;
use App\Support\TransactionFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Entrega al cliente o al proveedor el PDF o el XML de SUS documentos.
 *
 * La pertenencia se comprueba aquí y no solo en la pantalla: quien conozca el
 * número de otra transacción no debe poder bajar su factura cambiando el
 * número en la dirección. El cliente sale de la sesión, nunca de la petición.
 */
class PortalFileController extends Controller
{
    public function __invoke(TransactionFiles $archivos, int $transaction, string $kind): BinaryFileResponse
    {
        abort_unless(in_array($kind, [TransactionFiles::PDF, TransactionFiles::XML], true), 404);

        $usuario = request()->user();
        $transaccion = Transaction::findOrFail($transaction);

        $suyo = match (true) {
            $usuario->portalClientId() !== null => (int) $transaccion->customer === $usuario->portalClientId(),
            $usuario->portalProviderId() !== null => (int) $transaccion->vendor === $usuario->portalProviderId(),
            default => false,
        };

        // Se responde 404 y no 403 a propósito: un 403 confirmaría que el
        // documento existe.
        abort_unless($suyo, 404);

        $ruta = $archivos->path($transaccion, $kind);

        if ($ruta === null) {
            throw new NotFoundHttpException('El documento no tiene ese archivo.');
        }

        $nombre = $kind === TransactionFiles::PDF ? $transaccion->pdf_attach : $transaccion->xml_attach;

        return $kind === TransactionFiles::PDF
            ? response()->file($ruta, ['Content-Disposition' => 'inline; filename="'.$nombre.'"'])
            : response()->download($ruta, $nombre);
    }
}
