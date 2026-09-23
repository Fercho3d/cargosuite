<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * El contrato en PDF de un servicio.
 *
 * Conserva el acomodo del sistema original (`Service::upload()` en Yii2): el
 * archivo vive en `uploads/services/{service_id}/pdf/` dentro del mismo disco
 * compartido que las facturas y los documentos del booking, y la columna
 * `service.contract` guarda solo el nombre. Hay 156 contratos históricos ahí.
 */
class ServiceFiles
{
    private Filesystem $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('documentos');
    }

    /** Carpeta relativa dentro del disco compartido. */
    public function directory(int $serviceId): string
    {
        return "services/{$serviceId}/pdf";
    }

    /** Guarda el PDF con su nombre original (sin diagonales) y devuelve ese nombre. */
    public function store(int $serviceId, UploadedFile $archivo): string
    {
        $nombre = str_replace(['/', '\\'], '-', $archivo->getClientOriginalName());

        $this->disk->putFileAs($this->directory($serviceId), $archivo, $nombre);

        return $nombre;
    }

    /**
     * Ruta absoluta del contrato, o null si no está.
     *
     * Reintenta con la extensión en mayúsculas: en los datos históricos hay
     * archivos `.PDF` cuya columna dice `.pdf`, y el original hacía lo mismo.
     */
    public function path(int $serviceId, ?string $nombre): ?string
    {
        if (blank($nombre)) {
            return null;
        }

        $carpeta = $this->directory($serviceId);
        $enMayusculas = preg_replace_callback('/\.\w+$/', fn ($m) => strtoupper($m[0]), $nombre) ?? $nombre;

        foreach ([$nombre, $enMayusculas] as $candidato) {
            if ($this->disk->exists("{$carpeta}/{$candidato}")) {
                return $this->disk->path("{$carpeta}/{$candidato}");
            }
        }

        return null;
    }
}
