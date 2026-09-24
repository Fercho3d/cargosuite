<?php

namespace App\Support\Gps;

use App\Mail\FueraDeRutaMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa cuando una unidad se sale de la ruta planeada de su viaje.
 *
 * Con cada posición se mide la distancia a la ruta. Si las últimas
 * `gps.desvio_lecturas` posiciones quedan todas a más de `gps.desvio_km`, se
 * abre una alerta (y se manda por correo una sola vez); cuando la unidad
 * vuelve a la ruta, la alerta se cierra sola. Pedir varias lecturas evita
 * alarmar por un mal dato del GPS.
 *
 * Solo compara contra rutas ya calculadas (`ruta_viaje`): no llama al servicio
 * de rutas desde aquí, que corre con cada posición que llega.
 */
class VigilaRuta
{
    public function revisa(int $unidad, float $lat, float $lng, Carbon $fecha): void
    {
        $viaje = DB::table('booking')->where('unidad_id', $unidad)->where('is_draft', 0)->where('locked', 0)
            ->orderByDesc('booking_id')->first(['booking_id', 'booking_number']);
        $geometria = $viaje === null ? null : DB::table('ruta_viaje')->where('booking_id', $viaje->booking_id)->value('geometria');

        if ($geometria === null) {
            return;
        }

        $ruta = json_decode($geometria, true);
        $umbral = (float) config('gps.desvio_km');
        $distancia = self::distanciaKm($ruta, $lat, $lng);
        $abierta = DB::table('gps_alerta')->where('unidad_id', $unidad)->whereNull('fin')->first();

        if ($distancia <= $umbral) {
            if ($abierta !== null) {
                DB::table('gps_alerta')->where('alerta_id', $abierta->alerta_id)->update(['fin' => $fecha]);
            }

            return;
        }

        if ($abierta !== null) {
            DB::table('gps_alerta')->where('alerta_id', $abierta->alerta_id)
                ->update(['distancia_km' => max((float) $abierta->distancia_km, round($distancia, 1))]);

            return;
        }

        $ultimas = DB::table('gps_posicion')->where('unidad_id', $unidad)
            ->orderByDesc('fecha')->limit((int) config('gps.desvio_lecturas'))->get(['lat', 'lng']);

        $todasFuera = $ultimas->count() >= (int) config('gps.desvio_lecturas')
            && $ultimas->every(fn ($p) => self::distanciaKm($ruta, (float) $p->lat, (float) $p->lng) > $umbral);

        if (! $todasFuera) {
            return;
        }

        DB::table('gps_alerta')->insert([
            'unidad_id' => $unidad,
            'booking_id' => $viaje->booking_id,
            'tipo' => 'fuera_de_ruta',
            'inicio' => $fecha,
            'lat' => $lat,
            'lng' => $lng,
            'distancia_km' => round($distancia, 1),
        ]);

        $destinos = (array) config('marca.correo.avisos_operacion');

        if (! config('gps.alertas_correo') || $destinos === []) {
            return;
        }

        // El correo es un extra: si falla, la posición y la alerta ya quedaron
        // guardadas y la API del GPS no puede responder con error por eso.
        try {
            Mail::to($destinos)->send(new FueraDeRutaMail(
                (string) DB::table('unidad')->where('unidad_id', $unidad)->value('numero'),
                (string) $viaje->booking_number,
                round($distancia, 1),
                $lat,
                $lng,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Distancia en km de un punto a la ruta (una polilínea de [lat, lng]).
     *
     * Proyección plana alrededor del punto: a las distancias que importan aquí
     * (unos km) el error no se nota, y evita trigonometría esférica por tramo.
     *
     * @param  list<array{0: float, 1: float}>  $ruta
     */
    public static function distanciaKm(array $ruta, float $lat, float $lng): float
    {
        $kmLng = 111.32 * cos(deg2rad($lat));
        $kmLat = 110.57;
        $plano = fn (array $p) => [($p[1] - $lng) * $kmLng, ($p[0] - $lat) * $kmLat];
        $minima = INF;

        for ($i = 0, $n = count($ruta) - 1; $i < $n; $i++) {
            [$ax, $ay] = $plano($ruta[$i]);
            [$bx, $by] = $plano($ruta[$i + 1]);
            [$dx, $dy] = [$bx - $ax, $by - $ay];
            $largo = $dx * $dx + $dy * $dy;
            $t = $largo > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $largo)) : 0;
            $minima = min($minima, hypot($ax + $t * $dx, $ay + $t * $dy));
        }

        return $minima;
    }
}
