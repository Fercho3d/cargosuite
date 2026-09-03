<?php

namespace App\Http\Controllers;

use App\Models\Core\Transaction;
use App\Support\TransactionFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Entrega el PDF o el XML de una transacción, como `actionPdfFile` y
 * `actionXmlFile` en Yii2: el PDF se abre en el navegador y el XML se descarga.
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

        return $kind === TransactionFiles::PDF
            ? response()->file($ruta, ['Content-Disposition' => 'inline; filename="'.$nombre.'"'])
            : response()->download($ruta, $nombre);
    }
}
