<?php

namespace App\Support\Export;

use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\PaymentStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga del reporte por booking, con las columnas que pinta la pantalla
 * (`BookingReport`): un renglón por booking con su ingreso, egreso y utilidad.
 *
 * Mismas decisiones que `TransactionsExport`: se baja el filtro completo, en
 * trozos, y los importes van sin separador de miles para que Excel los sume.
 */
class BookingReportExport
{
    private const TROZO = 500;

    public function stream(TransactionFilters $filtros, string $nombre): StreamedResponse
    {
        return response()->streamDownload(function () use ($filtros) {
            $salida = fopen('php://output', 'w');

            // Sin la marca de orden de bytes, Excel se come los acentos.
            fwrite($salida, "\xEF\xBB\xBF");

            fputcsv($salida, [
                __('Booking'), __('Transacción'), __('Estado'), __('Alta del booking'),
                __('Ingreso'), __('Egreso'), __('Utilidad'),
            ]);

            $pagina = 1;

            do {
                $lote = TransactionQuery::make($filtros)->paginate(self::TROZO, $pagina);

                foreach ($lote as $fila) {
                    fputcsv($salida, [
                        trim((string) $fila->booking_number),
                        (string) $fila->tran_number,
                        PaymentStatus::for($fila)->label(),
                        $fila->booking_created_at ? substr((string) $fila->booking_created_at, 0, 10) : '',
                        (string) round((float) $fila->income, 4),
                        (string) round((float) $fila->expense, 4),
                        (string) round((float) $fila->income - (float) $fila->expense, 4),
                    ]);
                }

                flush();
                $pagina++;
            } while ($lote->hasMorePages());

            fclose($salida);
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
