<?php

namespace App\Support\Gps;

use App\Actions\Gps\RegistraPosicion;
use Illuminate\Support\Facades\DB;

/**
 * Mueve las unidades de la demostración por carreteras de México.
 *
 * Sin esto, a los 15 minutos de sembrar la demo todas las unidades saldrían
 * «sin señal». No guarda estado. Una unidad con viaje avanza sobre la
 * carretera de su ruta planeada, así su recorrido coincide con la ruta en el
 * mapa; una sin viaje va hacia una ciudad que cambia cada tres horas. Unas
 * cuantas se quedan detenidas o sin señal a propósito, para que los filtros
 * del mapa enseñen algo.
 */
class SimuladorDemo
{
    public const CIUDADES = [
        [25.6866, -100.3161], [25.4232, -101.0053], [22.1565, -100.9855], [20.5888, -100.3899],
        [19.4326, -99.1332], [20.6597, -103.3496], [21.8853, -102.2916], [21.1250, -101.6860],
        [27.4763, -99.5164], [19.1738, -96.1342], [19.0414, -98.2063], [19.1138, -104.3385],
    ];

    public function __construct(private RegistraPosicion $registra, private RutaDelViaje $rutas) {}

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

            $ruta = $n % 5 !== 2 ? $this->rutaDelViaje($n) : null;

            if ($ruta !== null) {
                [$lat, $lng, $kmh, $rumbo] = $this->sobreLaRuta($ruta, $lat, $lng, 62.0 + ($n % 4) * 7);

                // La de n % 4 == 0 se sale unos 12 km de su ruta los últimos 20
                // minutos de cada hora: así se ve la alerta abrirse y cerrarse.
                if ($n % 4 === 0 && (int) now()->format('i') >= 40) {
                    $lng += 0.12;
                }
            } elseif ($n % 5 !== 2) { // las demás avanzan; las de n % 5 == 2 están detenidas
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

    /**
     * La ruta planeada del viaje en curso de la unidad, si la hay.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    private function rutaDelViaje(int $unidad): ?array
    {
        $viaje = DB::table('booking')->where('unidad_id', $unidad)->where('is_draft', 0)->where('locked', 0)
            ->orderByDesc('booking_id')->value('booking_id');

        if ($viaje === null) {
            return null;
        }

        $guardada = DB::table('ruta_viaje')->where('booking_id', $viaje)->value('geometria');
        $ruta = $guardada !== null ? ['puntos' => json_decode($guardada, true), 'aproximada' => false] : $this->rutas->planeada((int) $viaje);

        return $ruta === null || $ruta['aproximada'] || count($ruta['puntos']) < 2 ? null : $ruta['puntos'];
    }

    /**
     * Un minuto de manejo sobre la ruta: desde el punto de la ruta más cercano,
     * se avanza de vértice en vértice lo que se recorre en un minuto. Una unidad
     * lejos de la ruta arranca en el origen; al final de la ruta, se detiene.
     *
     * @param  list<array{0: float, 1: float}>  $ruta
     * @return array{0: float, 1: float, 2: float, 3: ?int}
     */
    private function sobreLaRuta(array $ruta, float $lat, float $lng, float $kmh): array
    {
        $km = fn (array $a, array $b) => sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2) * 111;

        $cercano = 0;
        foreach ($ruta as $i => $p) {
            if ($km($p, [$lat, $lng]) < $km($ruta[$cercano], [$lat, $lng])) {
                $cercano = $i;
            }
        }

        if ($km($ruta[$cercano], [$lat, $lng]) > 20) {
            return [$ruta[0][0], $ruta[0][1], 0.0, null];
        }

        $ultimo = count($ruta) - 1;
        if ($cercano === $ultimo) {
            return [$ruta[$ultimo][0], $ruta[$ultimo][1], 0.0, null];
        }

        [$i, $andado] = [$cercano, 0.0];
        while ($i < $ultimo && $andado < $kmh / 60) {
            $andado += $km($ruta[$i], $ruta[$i + 1]);
            $i++;
        }

        $rumbo = (int) round(fmod(rad2deg(atan2($ruta[$i][1] - $ruta[$i - 1][1], $ruta[$i][0] - $ruta[$i - 1][0])) + 360, 360));

        return [$ruta[$i][0], $ruta[$i][1], $kmh, $rumbo];
    }
}
