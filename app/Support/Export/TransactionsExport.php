<?php

namespace App\Support\Export;

use App\Models\Core\Transaction;
use App\Queries\ProfitByBooking;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\PaymentStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga del listado de transacciones para abrirlo en Excel.
 *
 * Dos decisiones que conviene tener presentes:
 *
 * 1. **Se baja el filtro completo, no la página.** El `{export}` de Kartik del
 *    sistema viejo exporta solo lo que está pintado —50 renglones de 22 mil—,
 *    que casi nunca es lo que alguien quiere al pedir «pásamelo a Excel».
 * 2. **Se manda en trozos, no de un tirón**: el listado completo son decenas de
 *    miles de renglones y armar el archivo entero en memoria tumbaría el proceso.
 *
 * Los importes van **sin formato de miles** a propósito: con separadores, Excel
 * los toma como texto y no se pueden sumar.
 */
class TransactionsExport
{
    /** Renglones por vuelta. Ni tanto que ocupe memoria ni tan poco que sean mil consultas. */
    private const TROZO = 500;

    /** @var array<string, string> encabezado => propiedad de la fila */
    private const COLUMNAS = [
        'Booking' => 'booking_number',
        'Fecha' => 'tran_date',
        'Número' => 'tran_number',
        'Tipo' => 'tipo',
        'Aplicado a' => 'appliedTo',
        'Compañía' => 'companyName',
        'Ccy' => 'currency',
        'Importe' => 'amount_original',
        'TC' => 'exchange_value',
        'Sub 0 %' => 'sub_0_mxn',
        'Sub 16 %' => 'sub_16_mxn',
        'IVA 16 %' => 'tax_16_mxn',
        'Non Dec' => 'non_dec',
        'Ret. IVA' => 'tax_ret_mxn',
        'Total' => 'total_amount',
        'PDF' => 'pdf',
        'XML' => 'xml',
        'Pagado' => 'tran_paid_amount',
        'Estado' => 'estado',
        'CFDI' => 'seal',
    ];

    /** Columnas que solo lleva Costos, como su listado: van entre «Total» y «Pagado» y tras «Pagado». */
    private const COLUMNAS_COSTOS = [
        'Total' => ['Solicitud' => 'solicitud'],
        'Pagado' => [
            'Total natural' => 'total_natural_amount',
            'Saldo' => 'left_to_pay',
            'Utilidad del booking' => 'utilidad_booking',
        ],
    ];

    /** Columna que solo lleva Facturas. */
    private const COLUMNAS_FACTURAS = [
        'Total' => ['Pagado (TC de pago)' => 'total_amount_paid_tc'],
    ];

    /**
     * Las columnas de la pantalla que se descarga: las mismas que pinta la
     * tabla, en el mismo orden.
     *
     * @return array<string, string>
     */
    private function columnsFor(string $screen): array
    {
        $extras = match ($screen) {
            'bill' => self::COLUMNAS_COSTOS,
            'invoice' => self::COLUMNAS_FACTURAS,
            default => [],
        };

        $columnas = [];

        foreach (self::COLUMNAS as $encabezado => $propiedad) {
            $columnas[$encabezado] = $propiedad;
            $columnas += $extras[$encabezado] ?? [];
        }

        return $columnas;
    }

    public function stream(TransactionFilters $filtros, string $nombre, string $screen = 'all'): StreamedResponse
    {
        $columnas = $this->columnsFor($screen);

        return response()->streamDownload(function () use ($filtros, $columnas) {
            $salida = fopen('php://output', 'w');

            // Sin la marca de orden de bytes, Excel se come los acentos.
            fwrite($salida, "\xEF\xBB\xBF");

            fputcsv($salida, array_map(fn (string $c) => __($c), array_keys($columnas)));

            $pagina = 1;

            do {
                $lote = TransactionQuery::make($filtros)->paginate(self::TROZO, $pagina);
                $solicitudes = in_array('solicitud', $columnas, true) ? Transaction::requestNumbersFor($lote->items()) : [];
                // La utilidad es del booking, así que se resuelve por trozo y no
                // por renglón: una consulta cada 500 costos.
                $utilidades = in_array('utilidad_booking', $columnas, true)
                    ? (new ProfitByBooking($filtros))->forBookings(
                        collect($lote->items())->pluck('booking_id')->map(fn ($id) => (int) $id)->all()
                    )
                    : [];

                foreach ($lote as $fila) {
                    fputcsv($salida, $this->row($fila, $columnas, $solicitudes, $utilidades));
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

    /**
     * @param  array<string, string>  $columnas
     * @param  array<int, string>  $solicitudes  número de solicitud por `request_id`
     * @param  array<int, array<string, float>>  $utilidades  utilidad por booking
     * @return array<int, string>
     */
    private function row(object $fila, array $columnas, array $solicitudes, array $utilidades = []): array
    {
        $valores = [];

        foreach ($columnas as $propiedad) {
            $valores[] = match ($propiedad) {
                'tran_date' => $fila->tran_date ? substr((string) $fila->tran_date, 0, 10) : '',
                'tipo' => Transaction::typeLabel($fila->invoice_type, $fila->tran_type),
                'appliedTo' => (string) ($fila->customerName ?: $fila->vendorName),
                'estado' => PaymentStatus::for($fila)->label(),
                'seal' => (string) $fila->seal,
                'pdf' => filled($fila->pdf_attach) ? __('Sí') : __('No'),
                'xml' => filled($fila->xml_attach) ? __('Sí') : __('No'),
                'solicitud' => (string) ($solicitudes[$fila->request_id] ?? ''),
                'utilidad_booking' => isset($utilidades[(int) $fila->booking_id])
                    ? (string) round($utilidades[(int) $fila->booking_id]['profit_doc'], 2)
                    : '',
                // Los importes, en crudo: con separador de miles Excel los toma
                // como texto y deja de poder sumarlos.
                'amount_original', 'exchange_value', 'sub_0_mxn', 'sub_16_mxn', 'tax_16_mxn', 'non_dec',
                'tax_ret_mxn', 'total_amount', 'total_amount_paid_tc', 'tran_paid_amount',
                'total_natural_amount', 'left_to_pay' => $fila->{$propiedad} === null
                    ? ''
                    : (string) round((float) $fila->{$propiedad}, 4),
                default => trim((string) ($fila->{$propiedad} ?? '')),
            };
        }

        return $valores;
    }
}
