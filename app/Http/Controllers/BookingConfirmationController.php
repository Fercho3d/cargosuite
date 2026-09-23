<?php

namespace App\Http\Controllers;

use App\Models\Core\Booking;
use App\Support\Pdf\BookingConfirmation;
use Illuminate\Http\Response;

/**
 * La confirmación de booking en PDF, para verla o guardarla.
 *
 * Equivale a `BookingController::actionPdf()` de Yii2, que la abría en el
 * navegador. El correo con el mismo documento lo manda `SendBookingConfirmation`.
 */
class BookingConfirmationController extends Controller
{
    public function __invoke(BookingConfirmation $documento, int $booking): Response
    {
        // Para cualquier usuario interno, como el `pdf` del original: la ruta
        // ya va detrás de `EnsureUserIsInternal`, y el portal tiene la suya.
        $modelo = Booking::findOrFail($booking);

        return response($documento->pdf($modelo), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documento->fileName($modelo).'.pdf"',
        ]);
    }
}
