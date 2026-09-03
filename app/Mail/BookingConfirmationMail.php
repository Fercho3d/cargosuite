<?php

namespace App\Mail;

use App\Models\Core\Booking;
use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Support\Pdf\BookingConfirmation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Aviso de que un booking quedó en firme, con la confirmación en PDF adjunta.
 *
 * Porta `mail/createdBookingMail.php` de Yii2 y el envío de
 * `BookingController::sendCreateEmail()`.
 *
 * ⚠️ El original **truena aquí**: la plantilla pide `carrierModel->name` y la
 * naviera es un `Provider`, que no tiene esa columna (se llama `fullName`).
 * Yii2 responde con `UnknownPropertyException`, comprobado contra la base real,
 * así que el correo no sale en ningún booking que tenga naviera —es decir,
 * casi ninguno— y encima rompe la pantalla de alta, que llama a esto sin
 * `try`. Aquí se usa el nombre bueno; es lo único que no se puede «dejar igual».
 */
class BookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking)
    {
        // El correo se arma en el idioma de los DOCUMENTOS y no en el de quien
        // lo dispara: lo lee el cliente, no el operador. `locale()` es el
        // mecanismo propio del Mailable y cubre el asunto Y el cuerpo; envolver
        // solo el asunto no habría servido, porque el cuerpo lo pinta Laravel
        // más tarde, ya fuera de cualquier envoltura nuestra.
        $this->locale((string) config('marca.idioma_documentos'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Booking').' ['.$this->booking->booking_number.']');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.booking-confirmation', with: [
            'cliente' => Client::find($this->booking->client),
            'naviera' => Provider::find($this->booking->carrier_id)?->fullName,
            'buque' => DB::table('vessel')->where('vessel_id', $this->booking->vessel)->value('vessel_name'),
            'puertoCarga' => DB::table('loading_ports')->where('port_id', $this->booking->loading_port)->value('port_name'),
            'lugarRecoleccion' => DB::table('pickup_place')->where('pick_id', $this->booking->pick_up_place_id)->value('name'),
            'contenedores' => collect(
                DB::table('containers as c')
                    ->leftJoin('container_types as ct', 'ct.contType_id', '=', 'c.container_type')
                    ->where('c.booking', $this->booking->booking_id)
                    ->orderBy('c.container_ID')
                    ->get(['c.number', 'c.seal', 'c.comodity', 'c.quantity', 'ct.container_name'])
            ),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $documento = app(BookingConfirmation::class);

        return [
            Attachment::fromPath($documento->save($this->booking))
                ->as('booking_'.$documento->fileName($this->booking).'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
