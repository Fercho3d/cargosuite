<?php

namespace App\Support\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Las rutas vivas, listas para dibujarse en el panel.
 *
 * ⚠️ **La posición del medio es una ESTIMACIÓN, no un rastreo.** El sistema no
 * sabe dónde está el barco: no hay una sola posición en ninguna tabla y no la
 * habrá sin contratar un servicio de AIS, que es una suscripción aparte. Lo que
 * se dibuja es el avance entre la fecha de carga y la de arribo, y la pantalla
 * lo dice. Un puntito preciso sobre el océano que en realidad es una regla de
 * tres se ve muy bien hasta que el cliente lo compara con la realidad.
 *
 * La proyección es equirectangular —longitud y latitud a x e y, sin más—: para
 * un mapa de rutas a esta escala no se nota, y evita meter trigonometría que
 * después nadie sabe mantener.
 */
class RouteMap
{
    /** Lienzo del mapa. 2:1 es la proporción natural de la equirectangular. */
    public const ANCHO = 1000;

    public const ALTO = 500;

    /** Cuántos embarques se dibujan: más de una docena y no se distingue nada. */
    private const LIMITE = 12;

    /** Ventana de lo que se considera vivo, en días alrededor de la carga. */
    private const DIAS = 45;

    /**
     * @return list<array<string, mixed>>
     */
    public static function rutas(): array
    {
        $hoy = Carbon::now();

        $filas = DB::table('booking as b')
            ->join('loading_ports as lp', 'lp.port_id', '=', 'b.loading_port')
            ->join('dicharge_port as dp', 'dp.dicharge_port_id', '=', 'b.dicharge_port_id')
            ->leftJoin('client as c', 'c.client_id', '=', 'b.client')
            ->leftJoin('vessel as v', 'v.vessel_id', '=', 'b.vessel')
            ->where('b.is_draft', 0)
            ->where('b.mode', 10)
            // Solo los lugares con coordenadas: el resto no se puede dibujar y
            // no se inventa una posición para rellenar el mapa.
            ->whereNotNull('lp.latitud')
            ->whereNotNull('dp.latitud')
            ->whereNotNull('b.loading_EDT')
            ->whereBetween('b.loading_EDT', [
                $hoy->copy()->subDays(self::DIAS)->toDateString(),
                $hoy->copy()->addDays(self::DIAS)->toDateString(),
            ])
            ->orderByDesc('b.loading_EDT')
            ->limit(self::LIMITE)
            ->get([
                'b.booking_id', 'b.booking_number', 'b.loading_EDT', 'b.dicharge_ETA',
                'c.fullName as cliente', 'v.vessel_name as medio',
                'lp.port_name as origen', 'lp.latitud as origen_lat', 'lp.longitud as origen_lon',
                'dp.name as destino', 'dp.latitud as destino_lat', 'dp.longitud as destino_lon',
            ]);

        return $filas->map(function (object $fila) use ($hoy) {
            $desde = self::punto((float) $fila->origen_lat, (float) $fila->origen_lon);
            $hasta = self::punto((float) $fila->destino_lat, (float) $fila->destino_lon);
            $control = self::control($desde, $hasta);
            $avance = self::avance($fila->loading_EDT, $fila->dicharge_ETA, $hoy);

            return [
                'booking_id' => (int) $fila->booking_id,
                'booking' => trim((string) $fila->booking_number),
                'cliente' => (string) ($fila->cliente ?? ''),
                'medio' => (string) ($fila->medio ?? ''),
                'origen' => ['nombre' => trim((string) $fila->origen)] + $desde,
                'destino' => ['nombre' => trim((string) $fila->destino)] + $hasta,
                'control' => $control,
                'avance' => $avance,
                'posicion' => self::enLaCurva($desde, $control, $hasta, $avance),
            ];
        })->all();
    }

    /** Latitud y longitud al lienzo. @return array{x: float, y: float} */
    public static function punto(float $lat, float $lon): array
    {
        return [
            'x' => round((($lon + 180) / 360) * self::ANCHO, 2),
            'y' => round(((90 - $lat) / 180) * self::ALTO, 2),
        ];
    }

    /**
     * Punto de control de la curva: el medio de la cuerda, desplazado en
     * perpendicular. Sin esto, dos rutas entre los mismos puertos se dibujarían
     * una encima de otra y parecerían una sola.
     *
     * @param  array{x: float, y: float}  $desde
     * @param  array{x: float, y: float}  $hasta
     * @return array{x: float, y: float}
     */
    private static function control(array $desde, array $hasta): array
    {
        $dx = $hasta['x'] - $desde['x'];
        $dy = $hasta['y'] - $desde['y'];

        return [
            'x' => round($desde['x'] + $dx / 2 + $dy * 0.16, 2),
            'y' => round($desde['y'] + $dy / 2 - $dx * 0.16, 2),
        ];
    }

    /**
     * Qué parte del trayecto lleva, por fechas. Sin fecha de arribo se queda en
     * el origen: es más honesto que repartirlo a ojo.
     */
    private static function avance(?string $carga, ?string $arribo, Carbon $hoy): float
    {
        if ($carga === null || $arribo === null) {
            return 0.0;
        }

        $inicio = Carbon::parse($carga);
        $fin = Carbon::parse($arribo);
        $total = $inicio->diffInSeconds($fin, false);

        if ($total <= 0) {
            return $hoy->greaterThan($fin) ? 1.0 : 0.0;
        }

        return round(max(0.0, min(1.0, $inicio->diffInSeconds($hoy, false) / $total)), 4);
    }

    /**
     * Punto de una curva de Bézier cuadrática en `t`.
     *
     * @param  array{x: float, y: float}  $desde
     * @param  array{x: float, y: float}  $control
     * @param  array{x: float, y: float}  $hasta
     * @return array{x: float, y: float}
     */
    private static function enLaCurva(array $desde, array $control, array $hasta, float $t): array
    {
        $u = 1 - $t;

        return [
            'x' => round($u * $u * $desde['x'] + 2 * $u * $t * $control['x'] + $t * $t * $hasta['x'], 2),
            'y' => round($u * $u * $desde['y'] + 2 * $u * $t * $control['y'] + $t * $t * $hasta['y'], 2),
        ];
    }
}
