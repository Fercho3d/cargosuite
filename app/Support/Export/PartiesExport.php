<?php

namespace App\Support\Export;

use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga del listado de clientes o proveedores para abrirlo en Excel.
 *
 * Mismas decisiones que `TransactionsExport`: se baja el filtro completo y no
 * la página, y se manda en trozos para no armar el archivo entero en memoria.
 */
class PartiesExport
{
    /** Renglones por vuelta. */
    private const TROZO = 500;

    /**
     * @param  Builder  $consulta  Ya filtrada y ordenada, como la pinta la lista.
     * @param  array<string, string>  $columnas  Encabezado => columna de la tabla.
     * @param  array<string, array<int|string, string>>  $etiquetas  Columna => `[valor => texto]`
     *                                                               para los selectores, que en la base
     *                                                               guardan la clave y no el nombre.
     */
    public function stream(Builder $consulta, array $columnas, array $etiquetas, string $nombre): StreamedResponse
    {
        return response()->streamDownload(function () use ($consulta, $columnas, $etiquetas) {
            $salida = fopen('php://output', 'w');

            // Sin la marca de orden de bytes, Excel se come los acentos.
            fwrite($salida, "\xEF\xBB\xBF");

            fputcsv($salida, array_keys($columnas));

            $pagina = 1;

            do {
                // Solo las columnas del archivo (y lo que ya acote la consulta):
                // nunca `*`, que traería contraseñas y llaves del portal.
                $lote = $consulta->paginate(self::TROZO, array_values($columnas), 'page', $pagina);

                foreach ($lote as $fila) {
                    fputcsv($salida, array_map(
                        fn (string $columna) => isset($etiquetas[$columna])
                            ? (string) ($etiquetas[$columna][$fila->{$columna}] ?? $fila->{$columna} ?? '')
                            : trim((string) ($fila->{$columna} ?? '')),
                        array_values($columnas),
                    ));
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
