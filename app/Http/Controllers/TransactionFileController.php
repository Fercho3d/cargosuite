<?php

namespace App\Http\Controllers;

use App\Models\Core\Transaction;
use App\Support\TransactionFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Entrega el PDF o el XML de una transacción (`actionPdfFile` y `actionXmlFile`
 * en Yii2). Por omisión se abre en el navegador —el PDF en su visor, el XML
 * como texto—; con `?descargar=1` se baja como archivo.
 */
class TransactionFileController extends Controller
{
    public function __invoke(TransactionFiles $archivos, int $transaction, string $kind): BinaryFileResponse
    {
        abort_unless(in_array($kind, [TransactionFiles::PDF, TransactionFiles::XML], true), 404);

        $transaccion = Transaction::findOrFail($transaction);
        $ruta = $archivos->path($transaccion, $kind);

        if ($ruta === null) {
            throw new NotFoundHttpException('La transacción no tiene ese adjunto.');
        }

        $nombre = $kind === TransactionFiles::PDF ? $transaccion->pdf_attach : $transaccion->xml_attach;

        if (request()->boolean('descargar')) {
            return response()->download($ruta, $nombre);
        }

        return response()->file($ruta, [
            'Content-Type' => $kind === TransactionFiles::PDF ? 'application/pdf' : 'text/xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$nombre.'"',
        ]);
    }
}
