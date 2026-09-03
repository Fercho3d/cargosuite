<?php

namespace App\Actions\Bookings;

use App\Mail\BookingConfirmationMail;
use App\Models\Core\Booking;
use App\Models\Core\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Manda al cliente la confirmación de su booking, con el PDF adjunto.
 *
 * Porta `BookingController::sendCreateEmail()` de Yii2, que lo dispara al dar de
 * alta un booking en firme (`is_draft = 0` y `mode != 9`). Los destinatarios
 * salen de los correos de notificación del cliente: si no tiene ninguno, no se
 * manda nada, igual que el original.
 *
 * ⚠️ Diferencia deliberada: el original llama a esto sin `try`, y un fallo del
 * servidor de correo tumba la pantalla de alta **después** de haber guardado el
 * booking. Aquí el error se registra y la captura sigue: el booking ya está
 * guardado y volver a mandar el correo es un botón.
 */
class SendBookingConfirmation
{
    /**
     * @return array<int, string> A quién se le mandó.
     */
    public function handle(Booking $booking): array
    {
        $destinatarios = Client::find($booking->client)?->notificationEmails() ?? [];

        if ($destinatarios === []) {
            return [];
        }

        try {
            Mail::to($destinatarios)->send(new BookingConfirmationMail($booking));
        } catch (Throwable $e) {
            Log::warning('No se pudo mandar la confirmación del booking', [
                'booking' => $booking->booking_id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $destinatarios;
    }
}
