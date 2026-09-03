<?php

namespace App\Http\Controllers;

use App\Models\Core\PaymentRequest;
use App\Support\Pdf\PaymentRequestDocument;
use Illuminate\Http\Response;

/**
 * La solicitud de pago impresa, como `PaymentRequestController::actionDocument()`
 * de Yii2.
 */
class PaymentRequestDocumentController extends Controller
{
    public function __invoke(PaymentRequestDocument $documento, int $request): Response
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $solicitud = PaymentRequest::findOrFail($request);

        return response($documento->pdf($solicitud), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documento->fileName($solicitud).'.pdf"',
        ]);
    }
}
