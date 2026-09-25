<?php

namespace App\Mail;

use App\Support\Cotizaciones\Cotizaciones;
use App\Support\Marca;
use App\Support\Pdf\QuoteDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** La cotización al cliente, con el PDF adjunto, en el idioma de los documentos. */
class QuoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public int $cotizacionId)
    {
        $this->locale((string) config('marca.idioma_documentos'));
    }

    private function cotizacion(): object
    {
        return DB::table('cotizacion')->where('cotizacion_id', $this->cotizacionId)->first();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Cotización').' '.$this->cotizacion()->numero.' · '.Marca::empresa());
    }

    public function content(): Content
    {
        $c = $this->cotizacion();

        return new Content(view: 'mail.cotizacion', with: [
            'c' => $c,
            'destinatario' => Cotizaciones::destinatario($c),
            'ruta' => DB::table('ruta as r')
                ->join('loading_ports as o', 'o.port_id', '=', 'r.origen_id')
                ->join('dicharge_port as d', 'd.dicharge_port_id', '=', 'r.destino_id')
                ->where('r.ruta_id', $c->ruta_id)->selectRaw("CONCAT(o.port_name, ' → ', d.name) as nombre")->value('nombre'),
            'total' => Cotizaciones::totales(Cotizaciones::renglones($this->cotizacionId))['total'],
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $documento = app(QuoteDocument::class);

        return [
            Attachment::fromPath($documento->save($this->cotizacionId))
                ->as($documento->fileName($this->cotizacionId).'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
