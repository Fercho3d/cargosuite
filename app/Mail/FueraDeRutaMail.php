<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso interno: una unidad se salió de la ruta de su viaje. */
class FueraDeRutaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $unidad,
        public string $viaje,
        public float $distanciaKm,
        public float $lat,
        public float $lng,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('marca.correo.remitente.direccion'), config('marca.correo.remitente.nombre')),
            subject: __(':unidad fuera de ruta (:viaje)', ['unidad' => $this->unidad, 'viaje' => $this->viaje]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.fuera-de-ruta');
    }
}
