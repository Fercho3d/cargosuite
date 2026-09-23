<?php

namespace App\Actions\Transactions;

use App\Mail\InvoiceMail;
use App\Models\Core\Client;
use App\Models\Core\Transaction;
use App\Support\TransactionFiles;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Le manda al cliente su factura timbrada, con el PDF y el XML adjuntos.
 *
 * Es el mismo correo que en Yii2 sale por dos caminos: automáticamente al
 * timbrar (`actionTimbrar`) y a mano desde el listado (`actionReenviar`).
 *
 * A quién se le manda depende del interruptor de producción del timbrado: si se
 * está timbrando contra el ambiente de pruebas del PAC, los documentos no son
 * fiscales y **el cliente no debe recibirlos**, así que van solo a la copia
 * interna. El original decidía lo mismo, pero adivinando por el nombre del
 * servidor; aquí se reutiliza el interruptor explícito que ya usa el timbrado.
 */
class SendInvoice
{
    public const ENVIADA = 'enviada';

    public const SIN_DOCUMENTOS = 'sin_documentos';

    public const SIN_DESTINATARIOS = 'sin_destinatarios';

    public const ERROR = 'error';

    public function __construct(private TransactionFiles $archivos) {}

    public function handle(Transaction $transaccion, string $bookingNumber): string
    {
        // El original exige el PDF; sin él dice que la factura todavía no tiene
        // documentos y no manda nada.
        if (blank($transaccion->pdf_attach) || $this->archivos->path($transaccion, TransactionFiles::PDF) === null) {
            return self::SIN_DOCUMENTOS;
        }

        $destinatarios = $this->recipients($transaccion);

        if ($destinatarios === []) {
            return self::SIN_DESTINATARIOS;
        }

        // La copia interna va oculta solo cuando no es ya el destinatario: fuera
        // de producción el correo le llega a ella como TO y no hay que repetirla.
        $copiaOculta = array_values(array_diff(config('marca.correo.copia_facturas'), $destinatarios));

        try {
            Mail::to($destinatarios)->send(new InvoiceMail($transaccion, $bookingNumber, $copiaOculta));
            // Sin esto no hay forma de saber después si a un cliente le llegó su factura.
            Log::channel('facturas')->info('Factura enviada al cliente', [
                'transaccion' => $transaccion->transc_id,
                'para' => $destinatarios,
                'copia_oculta' => $copiaOculta,
            ]);
        } catch (Throwable $e) {
            Log::channel('facturas')->error('No se pudo mandar la factura al cliente', [
                'transaccion' => $transaccion->transc_id,
                'error' => $e->getMessage(),
            ]);

            return self::ERROR;
        }

        return self::ENVIADA;
    }

    /** Cómo contarle al usuario qué pasó con el correo. */
    public static function note(?string $estado): string
    {
        return match ($estado) {
            self::ENVIADA => __('La factura se le mandó al cliente.'),
            self::SIN_DOCUMENTOS => __('No se mandó por correo: la factura todavía no tiene documentos.'),
            self::SIN_DESTINATARIOS => __('No se mandó por correo: el cliente no tiene correos de notificación.'),
            self::ERROR => __('No se pudo mandar por correo; quedó anotado en la bitácora.'),
            default => '',
        };
    }

    /** @return array<int, string> */
    private function recipients(Transaction $transaccion): array
    {
        if (! config('timbrado.produccion')) {
            return config('marca.correo.copia_facturas');
        }

        return Client::find($transaccion->customer)?->notificationEmails() ?? [];
    }
}
