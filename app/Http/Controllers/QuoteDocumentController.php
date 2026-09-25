<?php

namespace App\Http\Controllers;

use App\Support\Pdf\QuoteDocument;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** La cotización en PDF, para verla o descargarla. */
class QuoteDocumentController extends Controller
{
    public function __invoke(QuoteDocument $documento, int $cotizacion): Response
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        abort_unless(DB::table('cotizacion')->where('cotizacion_id', $cotizacion)->exists(), 404);

        return response($documento->pdf($cotizacion), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documento->fileName($cotizacion).'.pdf"',
        ]);
    }
}
