<?php

namespace App\Http\Controllers;

use App\Support\BookingFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Entrega un documento adjunto de un booking.
 *
 * El nombre del archivo viaja en la dirección, así que se comprueba que de
 * verdad pertenezca a ese booking antes de servirlo.
 */
class BookingFileController extends Controller
{
    public function __invoke(BookingFiles $archivos, int $booking, string $nombre): BinaryFileResponse
    {
        $nombre = basename(urldecode($nombre));

        abort_unless($archivos->belongsTo($booking, $nombre), 404);

        $ruta = $archivos->path($booking, $nombre);

        abort_if($ruta === null, 404, 'El archivo ya no está en el servidor.');

        // `?ver=1` lo abre en el navegador, y solo si es PDF o imagen: lo demás
        // se descarga siempre. Por omisión se descarga.
        if (request()->boolean('ver') && BookingFiles::seAbreEnLinea($nombre)) {
            return response()->file($ruta, ['Content-Disposition' => 'inline; filename="'.$nombre.'"']);
        }

        return response()->download($ruta, $nombre);
    }
}
