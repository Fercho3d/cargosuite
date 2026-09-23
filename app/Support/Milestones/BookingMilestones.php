<?php

namespace App\Support\Milestones;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las fechas de los hitos de un expediente.
 *
 * ⚠️ **Escribe en dos sitios a propósito.** La fila de `hito_por_expediente` es
 * la buena, pero los hitos heredados se copian además a su columna de
 * `booking_continuity`, porque de esas columnas siguen saliendo:
 * · el PDF de confirmación, comparado carácter por carácter contra el sistema
 *   anterior en las pruebas de paridad;
 * · dos columnas y dos filtros del listado de expedientes;
 * · los avisos de tareas atrasadas;
 * · y el propio sistema Yii2, con el que la instalación original convive.
 *
 * Un hito nuevo —los que añada un negocio distinto— no tiene columna y vive solo
 * en la tabla nueva; el espejo simplemente no le aplica.
 */
class BookingMilestones
{
    /**
     * Fechas por clave de hito para un expediente. Los hitos sin capturar no
     * salen en el arreglo.
     *
     * @return array<string, string>
     */
    public static function de(int $booking): array
    {
        return self::deVarios([$booking])[$booking] ?? [];
    }

    /**
     * Lo mismo para varios expedientes de un tirón: la rejilla de continuidad
     * pinta 25 renglones y no puede hacer 25 consultas.
     *
     * @param  list<int>  $bookings
     * @return array<int, array<string, string>>
     */
    public static function deVarios(array $bookings): array
    {
        if ($bookings === []) {
            return [];
        }

        $claves = MilestoneCatalog::todos()->pluck('clave', 'hito_id');
        $salida = [];

        DB::table('hito_por_expediente')
            ->whereIn('booking', $bookings)
            ->whereNotNull('fecha')
            ->get(['booking', 'hito_id', 'fecha'])
            ->each(function (object $fila) use (&$salida, $claves): void {
                $clave = $claves[$fila->hito_id] ?? null;

                if ($clave !== null) {
                    $salida[(int) $fila->booking][$clave] = (string) $fila->fecha;
                }
            });

        return $salida;
    }

    /**
     * Guarda (o borra, con null) la fecha de un hito.
     *
     * Se crea al vuelo: hay expedientes viejos que nunca tuvieron continuidad y
     * no por eso deben quedarse sin captura.
     */
    public static function guarda(int $booking, string $clave, ?string $fecha, ?int $usuario = null): void
    {
        $hito = MilestoneCatalog::porClave($clave);

        if ($hito === null) {
            return;
        }

        // Llega de un `datetime-local` («2026-03-01T10:30») o como fecha sola;
        // se guarda siempre como datetime, que es lo que hay en las dos tablas.
        $fecha = blank($fecha) ? null : Carbon::parse($fecha)->format('Y-m-d H:i:s');

        DB::table('hito_por_expediente')->updateOrInsert(
            ['booking' => $booking, 'hito_id' => $hito->hito_id],
            ['fecha' => $fecha, 'modified_by' => $usuario, 'modified_at' => now()],
        );

        self::espeja($booking, $hito, $fecha, $usuario);
    }

    /**
     * La fecha como la espera un `datetime-local`: la guardada con su hora, o
     * hoy a las 00:00 si no hay nada. La hora es opcional para quien captura:
     * si no la sabe, deja las cero.
     */
    public static function paraCaptura(?string $fecha): string
    {
        return blank($fecha) ? now()->format('Y-m-d\T00:00') : Carbon::parse($fecha)->format('Y-m-d\TH:i');
    }

    /** Copia el hito heredado a su columna de siempre. Ver la nota de la clase. */
    private static function espeja(int $booking, object $hito, ?string $fecha, ?int $usuario): void
    {
        if ($hito->columna_legado === null || ! Schema::hasTable('booking_continuity')) {
            return;
        }

        $valores = [
            $hito->columna_legado => $fecha ?: null,
            'modified_by' => $usuario,
            'modified_at' => now(),
        ];

        $existente = DB::table('booking_continuity')->where('booking', $booking)->first();

        $existente === null
            ? DB::table('booking_continuity')->insert($valores + ['booking' => $booking])
            : DB::table('booking_continuity')->where('cont_id', $existente->cont_id)->update($valores);
    }
}
