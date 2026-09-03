<?php

namespace App\Support\Export;

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
        'Aplicado a' => 'appliedTo',
        'Compañía' => 'companyName',
        'Ccy' => 'currency',
        'Importe' => 'amount_original',
        'TC' => 'exchange_value',
        'Sub 0 %' => 'sub_0_mxn',
        'Sub 16 %' => 'sub_16_mxn',
        'IVA 16 %' => 'tax_16_mxn',
        'Ret. IVA' => 'tax_ret_mxn',
        'Total' => 'total_amount',
        'Pagado' => 'tran_paid_amount',
        'Estado' => 'estado',
        'CFDI' => 'seal',
    ];

    public function stream(TransactionFilters $filtros, string $nombre): StreamedResponse
    {
        return response()->streamDownload(function () use ($filtros) {
            $salida = fopen('php://output', 'w');

            // Sin la marca de orden de bytes, Excel se come los acentos.
            fwrite($salida, "\xEF\xBB\xBF");

            fputcsv($salida, array_map(fn (string $c) => __($c), array_keys(self::COLUMNAS)));

            $pagina = 1;

            do {
                $lote = TransactionQuery::make($filtros)->paginate(self::TROZO, $pagina);

                foreach ($lote as $fila) {
                    fputcsv($salida, $this->row($fila));
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

    /** @return array<int, string> */
    private function row(object $fila): array
    {
        $valores = [];

        foreach (self::COLUMNAS as $propiedad) {
            $valores[] = match ($propiedad) {
                'tran_date' => $fila->tran_date ? substr((string) $fila->tran_date, 0, 10) : '',
                'appliedTo' => (string) ($fila->customerName ?: $fila->vendorName),
                'estado' => PaymentStatus::for($fila)->label(),
                'seal' => (string) $fila->seal,
                // Los importes, en crudo: con separador de miles Excel los toma
                // como texto y deja de poder sumarlos.
                'amount_original', 'exchange_value', 'sub_0_mxn', 'sub_16_mxn',
                'tax_16_mxn', 'tax_ret_mxn', 'total_amount', 'tran_paid_amount' => $fila->{$propiedad} === null
                    ? ''
                    : (string) round((float) $fila->{$propiedad}, 4),
                default => trim((string) ($fila->{$propiedad} ?? '')),
            };
        }

        return $valores;
    }
}
