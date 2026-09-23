<?php

namespace App\Http\Controllers;

use App\Models\Core\Booking;
use App\Queries\TransactionFilters;
use App\Support\Export\BookingReportExport;
use App\Support\Export\TransactionsExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga del listado de transacciones.
 *
 * Lee los MISMOS parámetros que la pantalla deja en la dirección, así que el
 * archivo trae exactamente lo que se está viendo: basta con copiar el filtro.
 */
class TransactionExportController extends Controller
{
    public function __invoke(Request $request, TransactionsExport $exportacion, string $screen): StreamedResponse
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        abort_unless(in_array($screen, ['invoice', 'bill', 'all', 'booking', 'report'], true), 404);

        $filtros = TransactionFilters::make([
            'tran_number' => $request->query('num') ?: null,
            'booking_number' => $request->query('bk') ?: null,
            'appliedTo' => $request->query('q') ?: null,
            'dates' => $screen === 'report' ? null : ($request->query('f') ?: null),
            'dates_booking' => $screen === 'report' ? ($request->query('f') ?: null) : null,
            'company_id' => $request->query('co') !== null ? (int) $request->query('co') : null,
            'account' => $request->query('ccy') !== null ? (int) $request->query('ccy') : null,
            'paid' => $request->query('pago') !== null && $request->query('pago') !== ''
                ? (int) $request->query('pago')
                : null,
            'showCancelled' => (int) $request->query('canc', '0'),
            'sort' => (string) $request->query('ord', 'transc_id'),
            'direction' => (string) $request->query('dir', 'desc'),
        ]);

        // El filtro base de cada pantalla, igual que en el listado. Un booking
        // que es cotización (modo 9) se pide como tal o la descarga sale vacía.
        match ($screen) {
            'invoice' => $filtros->type = [0],
            'bill' => [$filtros->type = [1, 2], $filtros->paymentMode = true],
            'booking' => [
                $filtros->booking = (int) $request->query('booking'),
                $filtros->showQuatation = Booking::find((int) $request->query('booking'))?->isQuotation() ?? false,
            ],
            'report' => $filtros->groupBy = 'booking',
            default => null,
        };

        $filtros->applyDocumentType($request->query('tipo'));

        $nombre = 'transacciones-'.$screen.'-'.now()->format('Ymd-His').'.csv';

        // El reporte por booking tiene sus propias columnas (ingreso, egreso y
        // utilidad por booking), no las del listado de transacciones.
        if ($screen === 'report') {
            return app(BookingReportExport::class)->stream($filtros, 'reporte-por-booking-'.now()->format('Ymd-His').'.csv');
        }

        return $exportacion->stream($filtros, $nombre, $screen);
    }
}
