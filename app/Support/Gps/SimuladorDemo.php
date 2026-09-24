<?php

namespace App\Support\Gps;

use App\Actions\Gps\RegistraPosicion;
use Illuminate\Support\Facades\DB;

/**
 * Mueve las unidades de la demostración por carreteras de México.
 *
 * Sin esto, a los 15 minutos de sembrar la demo todas las unidades saldrían
 * «sin señal». No guarda estado: cada unidad va hacia una ciudad que cambia
 * cada tres horas, a su velocidad, y unas cuantas se quedan detenidas o sin
 * señal a propósito, para que los filtros del mapa enseñen algo.
 */
class SimuladorDemo
{
    public const CIUDADES = [
        [25.6866, -100.3161], [25.4232, -101.0053], [22.1565, -100.9855], [20.5888, -100.3899],
        [19.4326, -99.1332], [20.6597, -103.3496], [21.8853, -102.2916], [21.1250, -101.6860],
        [27.4763, -99.5164], [19.1738, -96.1342], [19.0414, -98.2063], [19.1138, -104.3385],
    ];

    public function __construct(private RegistraPosicion $registra) {}

    public function avanza(): int
    {
        $movidas = 0;

        $equipos = DB::table('gps_dispositivo')->where('identificador', 'like', 'DEMO-%')
            ->where('activo', 1)->whereNotNull('unidad_id')->whereNotNull('ultima_lat')->get();

        foreach ($equipos as $e) {
            $n = (int) $e->unidad_id;

            if ($n % 7 === 3) {
                continue; // la que se quedó sin señal
            }

            [$lat, $lng, $kmh] = [(float) $e->ultima_lat, (float) $e->ultima_lng, 0.0];
            $rumbo = $e->ultimo_rumbo === null ? null : (int) $e->ultimo_rumbo;

            if ($n % 5 !== 2) { // las demás avanzan; las de n % 5 == 2 están detenidas
                [$destLat, $destLng] = self::CIUDADES[($n + intdiv(time(), 10800)) % count(self::CIUDADES)];
                $kmh = 62.0 + ($n % 4) * 7;
                $paso = $kmh / 60 / 111; // grados en un minuto, aproximado
                $dLat = $destLat - $lat;
                $dLng = $destLng - $lng;
                $distancia = sqrt($dLat ** 2 + $dLng ** 2);

                if ($distancia <= $paso) {
                    [$lat, $lng, $kmh] = [$destLat, $destLng, 0.0];
                } else {
                    $lat += $dLat / $distancia * $paso;
                    $lng += $dLng / $distancia * $paso;
                    $rumbo = (int) round(fmod(rad2deg(atan2($dLng, $dLat)) + 360, 360));
                }
            }

            ($this->registra)((string) $e->identificador, $lat, $lng, $kmh, $rumbo, now());
            $movidas++;
        }

        return $movidas;
    }
}
