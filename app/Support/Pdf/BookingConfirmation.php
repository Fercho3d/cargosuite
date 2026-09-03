<?php

namespace App\Support\Pdf;

use App\Models\Core\Booking;
use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\Documentos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La confirmación de booking en PDF: el documento que se le manda al cliente
 * cuando su embarque queda en firme.
 *
 * Porta `Booking::generateBokingConfirmation()` de Yii2. Ahí el documento se
 * armaba concatenando HTML dentro del modelo, 220 líneas de comillas; aquí el
 * texto vive en una plantilla y esta clase solo junta los datos.
 */
class BookingConfirmation
{
    public function __construct(private PdfWriter $pdf) {}

    public function pdf(Booking $booking): string
    {
        return $this->pdf->render($this->html($booking), $this->css(), (string) $booking->booking_number);
    }

    /** Deja el PDF en disco para poder adjuntarlo a un correo. */
    public function save(Booking $booking): string
    {
        $carpeta = storage_path('app/bookings');

        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        return $this->pdf->save(
            $this->html($booking),
            $carpeta.'/booking_'.$this->fileName($booking).'.pdf',
            $this->css(),
            (string) $booking->booking_number,
        );
    }

    /**
     * Nombre de archivo sin los caracteres que dan guerra, igual que
     * `Booking::sanitize_file_name()`.
     */
    public function fileName(Booking $booking): string
    {
        return str_replace(
            [' ', '"', "'", '&', '/', '\\', '?', '#'],
            '_',
            (string) $booking->booking_number,
        );
    }

    public function html(Booking $booking): string
    {
        return Documentos::conIdioma(fn () => $this->arma($booking));
    }

    private function arma(Booking $booking): string
    {
        $contenedores = $this->containers($booking);
        $piezas = (float) $contenedores->sum('quantity');
        $esCotizacion = $booking->isQuotation();
        $facturas = $esCotizacion ? $this->invoices($booking) : collect();

        return view('pdf.booking-confirmation', [
            'booking' => $booking,
            'titulo' => $esCotizacion ? __('impresos.quotation_title') : __('impresos.confirmation_title'),
            'cliente' => Client::find($booking->client),
            'recoleccion' => DB::table('pickup_place')->where('pick_id', $booking->pick_up_place_id)->first(),
            'continuidad' => DB::table('booking_continuity')->where('booking', $booking->booking_id)->first(),
            'naviera' => Provider::find($booking->carrier_id),
            'puertoCarga' => DB::table('loading_ports')->where('port_id', $booking->loading_port)->value('port_name'),
            'puertoDescarga' => DB::table('dicharge_port')->where('dicharge_port_id', $booking->dicharge_port_id)->value('name'),
            'destinoFinal' => DB::table('final_destination')->where('final_destination_id', $booking->final_destination_id)->value('name'),
            'contenedores' => $contenedores,
            'piezas' => $piezas,
            'esCotizacion' => $esCotizacion,
            'facturas' => $facturas,
            // Rareza del original que se conserva: el total de la tabla de
            // facturas arranca con el número de piezas, porque reutiliza la
            // misma variable que venía sumando los contenedores.
            'totalFacturado' => $piezas + (float) $facturas->sum('total_amount'),
        ])->render();
    }

    private function css(): string
    {
        return (string) file_get_contents(resource_path('views/pdf/booking.css'));
    }

    /** @return Collection<int, object> */
    private function containers(Booking $booking)
    {
        return collect(
            DB::table('containers as c')
                ->leftJoin('container_types as ct', 'ct.contType_id', '=', 'c.container_type')
                ->where('c.booking', $booking->booking_id)
                ->orderBy('c.container_ID')
                ->get(['c.number', 'c.seal', 'c.comodity', 'c.quantity', 'ct.container_name'])
        );
    }

    /**
     * Las facturas del booking, con los mismos modos que pedía el original en
     * `Booking::getInvoices()`: solo las de cliente y sin invertir signos.
     */
    private function invoices(Booking $booking)
    {
        return TransactionQuery::make(TransactionFilters::make([
            'booking' => $booking->booking_id,
            'showQuatation' => true,
            'noExchange' => false,
            'noNegative' => false,
            'type_in' => [0],
        ]))->get();
    }
}
