<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La maniobra de vacío («Empty Pass») entra al catálogo de hitos.
 *
 * `booking_continuity.vacuum_maneuver` existía, el formulario de continuidad
 * del sistema de origen la capturaba como primer paso y los avisos de tareas
 * atrasadas la vigilan, pero el catálogo `hito` nació sin ella y no se podía
 * ver ni capturar. Se agrega antes de la recolección, como allá.
 *
 * Solo aditiva y solo donde hay catálogo marítimo (existe el hito
 * `pickup_date`): en una vertical de camiones no significa nada. Si ya está,
 * no se toca. Las fechas que ya había en la columna pasan a
 * `hito_por_expediente`; están como texto «dd-mm-aaaa hh:mm:ss», que es como
 * las escribía el selector del sistema viejo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hito') || DB::table('hito')->where('columna_legado', 'vacuum_maneuver')->exists()) {
            return;
        }

        $recoleccion = DB::table('hito')->where('clave', 'pickup_date')->first();

        if ($recoleccion === null) {
            return;
        }

        $hitoId = DB::table('hito')->insertGetId([
            'clave' => 'vacuum_maneuver',
            'etiqueta' => 'Maniobra de vacío',
            'orden' => max(0, (int) $recoleccion->orden - 5),
            'activo' => 1,
            'columna_legado' => 'vacuum_maneuver',
        ], 'hito_id');

        $this->rellenaDesdeLaColumna($hitoId);
    }

    public function down(): void
    {
        $hitoId = DB::table('hito')->where('clave', 'vacuum_maneuver')->value('hito_id');

        if ($hitoId !== null) {
            DB::table('hito_por_expediente')->where('hito_id', $hitoId)->delete();
            DB::table('hito')->where('hito_id', $hitoId)->delete();
        }
    }

    private function rellenaDesdeLaColumna(int $hitoId): void
    {
        if (! Schema::hasTable('booking_continuity') || ! Schema::hasColumn('booking_continuity', 'vacuum_maneuver')) {
            return;
        }

        DB::table('booking_continuity')
            ->whereNotNull('vacuum_maneuver')
            ->where('vacuum_maneuver', '<>', '')
            ->orderBy('cont_id')
            ->select(['booking', 'vacuum_maneuver', 'modified_by', 'modified_at'])
            ->chunk(500, function ($filas) use ($hitoId) {
                $porInsertar = [];

                foreach ($filas as $fila) {
                    $fecha = $this->fecha((string) $fila->vacuum_maneuver);

                    if ($fecha === null) {
                        continue;
                    }

                    $porInsertar[] = [
                        'booking' => $fila->booking,
                        'hito_id' => $hitoId,
                        'fecha' => $fecha,
                        'modified_by' => $fila->modified_by,
                        'modified_at' => $fila->modified_at,
                    ];
                }

                if ($porInsertar !== []) {
                    DB::table('hito_por_expediente')->insertOrIgnore($porInsertar);
                }
            });
    }

    /** «dd-mm-aaaa hh:mm:ss» del selector viejo, o lo que Carbon entienda. */
    private function fecha(string $texto): ?string
    {
        try {
            $momento = preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/', $texto) === 1
                ? Carbon::createFromFormat('d-m-Y H:i:s', $texto)
                : Carbon::parse($texto);
        } catch (Throwable) {
            return null;
        }

        return $momento->format('Y-m-d H:i:s');
    }
};
