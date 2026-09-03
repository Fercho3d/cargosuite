<?php

namespace App\Support;

use App\Models\Core\Transaction;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Adjuntos de una transacción: la factura en PDF y su XML.
 *
 * Conserva la organización del sistema original, rarezas incluidas: **los dos
 * archivos viven en la subcarpeta `pdf`**, y la columna guarda solo el nombre.
 * Cambiar el acomodo dejaría a Yii2 sin encontrar los archivos existentes.
 */
class TransactionFiles
{
    public const PDF = 'pdf';

    public const XML = 'xml';

    private Filesystem $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('documentos');
    }

    /** Carpeta relativa dentro del disco compartido. */
    public function directory(int $transactionId): string
    {
        return "transactions/{$transactionId}/pdf";
    }

    /** Guarda el archivo con su nombre original y devuelve ese nombre. */
    public function store(Transaction $transaccion, UploadedFile $archivo): string
    {
        $nombre = $archivo->getClientOriginalName();

        $this->disk->putFileAs($this->directory($transaccion->transc_id), $archivo, $nombre);

        return $nombre;
    }

    /**
     * Ruta absoluta del adjunto, o null si no está.
     *
     * Reintenta con la extensión en mayúsculas porque en los datos históricos
     * hay archivos guardados como `.PDF` mientras la columna dice `.pdf`; el
     * sistema original hace exactamente el mismo reintento.
     */
    public function path(Transaction $transaccion, string $kind): ?string
    {
        $nombre = $kind === self::PDF ? $transaccion->pdf_attach : $transaccion->xml_attach;

        if (blank($nombre)) {
            return null;
        }

        $carpeta = $this->directory($transaccion->transc_id);

        foreach ([$nombre, $this->upperExtension($nombre)] as $candidato) {
            if ($this->disk->exists("{$carpeta}/{$candidato}")) {
                return $this->disk->path("{$carpeta}/{$candidato}");
            }
        }

        return null;
    }

    private function upperExtension(string $nombre): string
    {
        return preg_replace_callback('/\.\w+$/', fn ($m) => strtoupper($m[0]), $nombre) ?? $nombre;
    }
}
