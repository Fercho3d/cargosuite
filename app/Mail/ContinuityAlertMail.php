<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de que una tarea del booking sigue sin marcarse.
 *
 * Porta `notificationLv1Mail` / `notificationLv2Mail` de Yii2, que son la misma
 * plantilla: lo único que cambia entre niveles es el asunto —el primero avisa
 * antes de la fecha y el segundo cuando ya se pasó— y el remitente, que aquí es
 * el del sistema y no el de facturación.
 */
class ContinuityAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Todavía no llega la fecha estimada. */
    public const AVISO = 'aviso';

    /** La fecha ya se cumplió o se pasó. */
    public const VENCIDO = 'vencido';

    public function __construct(
        public string $bookingNumber,
        public string $taskLabel,
        public string $date,
        public string $nivel = self::AVISO,
    ) {
        // El correo se arma en el idioma de los DOCUMENTOS y no en el de quien
        // lo dispara: lo lee el cliente, no el operador. `locale()` es el
        // mecanismo propio del Mailable y cubre el asunto Y el cuerpo; envolver
        // solo el asunto no habría servido, porque el cuerpo lo pinta Laravel
        // más tarde, ya fuera de cualquier envoltura nuestra.
        $this->locale((string) config('marca.idioma_documentos'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('marca.correo.remitente.direccion'),
                config('marca.correo.remitente.nombre'),
            ),
            subject: $this->nivel === self::VENCIDO
                ? $this->taskLabel.' task deadline! booking['.$this->bookingNumber.']'
                : $this->taskLabel.' task is not completed booking['.$this->bookingNumber.']',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.continuity-alert');
    }
}
