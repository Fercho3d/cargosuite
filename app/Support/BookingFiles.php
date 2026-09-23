<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Documentos que se adjuntan a un booking.
 *
 * Cada cliente pide los suyos: `fields_by_client` dice qué campos le aplican,
 * `file_fields` es el catálogo de esos campos y `files_by_booking` guarda, por
 * booking y campo, la lista de archivos.
 *
 * **El formato de esa lista es heredado**: los nombres van en una sola columna
 * separados por « / ». Se conserva para que el sistema viejo siga leyéndolos.
 */
class BookingFiles
{
    /** Separador de la lista de archivos en `files_by_booking.value`. */
    private const SEPARADOR = ' / ';

    /** Lo que se puede adjuntar: las mismas extensiones que aceptaba el original. */
    public const EXTENSIONES = ['pdf', 'jpg', 'jpeg', 'png', 'zip', 'xml', 'xls', 'xlsx', 'doc', 'docx'];

    /**
     * Lo que se abre en el navegador; lo demás se descarga. Un XML o un XLS
     * servido en línea o se pinta como texto suelto o lo intercepta un
     * complemento; descargado, se abre con lo que corresponde.
     */
    public const EN_LINEA = ['pdf', 'jpg', 'jpeg', 'png'];

    public static function seAbreEnLinea(string $nombre): bool
    {
        return in_array(strtolower(pathinfo($nombre, PATHINFO_EXTENSION)), self::EN_LINEA, true);
    }

    private Filesystem $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('documentos');
    }

    public function directory(int $bookingId): string
    {
        return "bookings/{$bookingId}/docs";
    }

    /**
     * Campos de documento que le aplican a un booking, con lo ya subido.
     *
     * @return array<int, object{field_id: int, label: string, files: string[]}>
     */
    public function fieldsFor(int $bookingId, ?int $clientId): array
    {
        if ($clientId === null) {
            return [];
        }

        return DB::table('fields_by_client as fc')
            ->join('file_fields as ff', 'ff.field_id', '=', 'fc.field_id')
            ->leftJoin('files_by_booking as fb', function ($join) use ($bookingId) {
                $join->on('fb.field_id', '=', 'fc.field_id')->where('fb.booking_id', '=', $bookingId);
            })
            ->where('fc.client_id', $clientId)
            ->orderBy('ff.label')
            ->get(['ff.field_id', 'ff.label', 'ff.field', 'fb.value'])
            ->map(fn ($fila) => (object) [
                'field_id' => (int) $fila->field_id,
                'label' => $fila->label ?: $fila->field,
                'files' => $this->names($fila->value),
            ])
            ->all();
    }

    /** @return string[] */
    public function names(?string $value): array
    {
        return collect(explode(self::SEPARADOR, (string) $value))
            ->map(fn (string $nombre) => trim($nombre))
            ->filter()
            ->values()
            ->all();
    }

    /** Guarda el archivo y lo agrega a la lista del campo. */
    public function store(int $bookingId, int $fieldId, UploadedFile $archivo): string
    {
        $nombre = str_replace('/', '-', $archivo->getClientOriginalName());

        $this->disk->putFileAs($this->directory($bookingId), $archivo, $nombre);

        $fila = DB::table('files_by_booking')
            ->where('booking_id', $bookingId)
            ->where('field_id', $fieldId)
            ->first();

        $nombres = array_values(array_unique([...$this->names($fila->value ?? null), $nombre]));
        $valor = implode(self::SEPARADOR, $nombres);

        $fila === null
            ? DB::table('files_by_booking')->insert(['booking_id' => $bookingId, 'field_id' => $fieldId, 'value' => $valor])
            : DB::table('files_by_booking')->where('booking_file_id', $fila->booking_file_id)->update(['value' => $valor]);

        return $nombre;
    }

    /**
     * Quita el archivo de la lista. **No lo borra del disco**, como el
     * `delete-file` del original, que solo quitaba el renglón: el mismo nombre
     * puede estar adjunto en otro campo, y un documento que operación quitó
     * por error se recupera volviéndolo a listar, no volviéndolo a pedir.
     */
    public function remove(int $bookingId, int $fieldId, string $nombre): void
    {
        $fila = DB::table('files_by_booking')
            ->where('booking_id', $bookingId)
            ->where('field_id', $fieldId)
            ->first();

        if ($fila === null) {
            return;
        }

        $nombres = array_values(array_filter($this->names($fila->value), fn (string $n) => $n !== $nombre));

        DB::table('files_by_booking')
            ->where('booking_file_id', $fila->booking_file_id)
            ->update(['value' => implode(self::SEPARADOR, $nombres)]);
    }

    /** Ruta absoluta del archivo, o null si no está en el disco. */
    public function path(int $bookingId, string $nombre): ?string
    {
        $ruta = $this->directory($bookingId).'/'.$nombre;

        return $this->disk->exists($ruta) ? $this->disk->path($ruta) : null;
    }

    /** ¿El archivo pertenece de verdad a ese booking? */
    public function belongsTo(int $bookingId, string $nombre): bool
    {
        return DB::table('files_by_booking')
            ->where('booking_id', $bookingId)
            ->get(['value'])
            ->contains(fn ($fila) => in_array($nombre, $this->names($fila->value), true));
    }
}
