<?php

namespace App\Actions\Gps;

use App\Support\Gps\VigilaRuta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Guarda una posición de un equipo GPS ya normalizada (grados, km/h, fecha).
 *
 * El equipo se busca por su identificador; uno que nadie dio de alta se
 * ignora. La «última posición» del equipo solo avanza: los equipos reenvían en
 * desorden lo que acumularon sin señal, y una posición vieja no puede mover la
 * unidad hacia atrás en el mapa.
 */
class RegistraPosicion
{
    public function __construct(private VigilaRuta $vigila) {}

    /** @return bool Si se guardó (false: equipo desconocido o dado de baja). */
    public function __invoke(string $identificador, float $lat, float $lng, ?float $kmh, ?int $rumbo, Carbon $fecha): bool
    {
        $equipo = DB::table('gps_dispositivo')->where('identificador', $identificador)->where('activo', 1)->first();

        if ($equipo === null) {
            return false;
        }

        DB::table('gps_posicion')->insert([
            'dispositivo_id' => $equipo->dispositivo_id,
            'unidad_id' => $equipo->unidad_id,
            'lat' => $lat,
            'lng' => $lng,
            'velocidad' => $kmh === null ? null : round($kmh, 1),
            'rumbo' => $rumbo,
            'fecha' => $fecha,
            'recibido_en' => now(),
        ]);

        DB::table('gps_dispositivo')
            ->where('dispositivo_id', $equipo->dispositivo_id)
            ->where(fn ($q) => $q->whereNull('ultima_senal')->orWhere('ultima_senal', '<=', $fecha))
            ->update([
                'ultima_lat' => $lat,
                'ultima_lng' => $lng,
                'ultima_velocidad' => $kmh === null ? null : round($kmh, 1),
                'ultimo_rumbo' => $rumbo,
                'ultima_senal' => $fecha,
            ]);

        if ($equipo->unidad_id !== null) {
            $this->vigila->revisa((int) $equipo->unidad_id, $lat, $lng, $fecha);
        }

        return true;
    }
}
