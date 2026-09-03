<?php

namespace App\Http\Controllers;

use App\Queries\TransactionFilters;
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

        // El filtro base de cada pantalla, igual que en el listado.
        match ($screen) {
            'invoice' => $filtros->type = [0],
            'bill' => [$filtros->type = [1, 2], $filtros->paymentMode = true],
            'booking' => $filtros->booking = (int) $request->query('booking'),
            'report' => $filtros->groupBy = 'booking',
            default => null,
        };

        $nombre = 'transacciones-'.$screen.'-'.now()->format('Ymd-His').'.csv';

        return $exportacion->stream($filtros, $nombre);
    }
}
