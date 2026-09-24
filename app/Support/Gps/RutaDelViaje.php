<?php

namespace App\Support\Gps;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * La ruta de un viaje, para dibujarla en el mapa.
 *
 * - **Planeada**: por carretera, del origen al destino pasando por el punto de
 *   carga y el de entrega (los que tengan coordenadas). La calcula el servicio
 *   de rutas (`gps.rutas`) una sola vez y se guarda en `ruta_viaje`.
 * - **Recorrido**: lo que mandó el GPS de la unidad desde el día de la carga.
 *
 * Si el servicio no responde, la planeada es una línea recta entre las paradas,
 * marcada como aproximada y sin guardar, para volver a intentarlo después.
 */
class RutaDelViaje
{
    private const MAX_POSICIONES = 2000;

    private const MAX_PUNTOS_RUTA = 600;

    /**
     * @return array{puntos: list<array{0: float, 1: float}>, paradas: list<array{nombre: string, lat: float, lng: float}>, km: ?float, minutos: ?int, aproximada: bool}|null
     */
    public function planeada(int $booking): ?array
    {
        $paradas = $this->paradas($booking);

        if (count($paradas) < 2) {
            return null;
        }

        $firma = md5(json_encode(array_map(fn ($p) => [$p['lat'], $p['lng']], $paradas)));
        $guardada = DB::table('ruta_viaje')->where('booking_id', $booking)->where('firma', $firma)->first();

        if ($guardada !== null) {
            return [
                'puntos' => json_decode($guardada->geometria, true),
                'paradas' => $paradas,
                'km' => $guardada->distancia_km === null ? null : (float) $guardada->distancia_km,
                'minutos' => $guardada->duracion_min === null ? null : (int) $guardada->duracion_min,
                'aproximada' => false,
            ];
        }

        $proveedor = (string) config('gps.rutas.proveedor');
        $calculada = $this->calcula($proveedor, $paradas);

        if ($calculada === null) {
            return [
                'puntos' => array_map(fn ($p) => [$p['lat'], $p['lng']], $paradas),
                'paradas' => $paradas,
                'km' => null,
                'minutos' => null,
                'aproximada' => true,
            ];
        }

        DB::table('ruta_viaje')->updateOrInsert(['booking_id' => $booking], [
            'firma' => $firma,
            'proveedor' => $proveedor,
            'distancia_km' => $calculada['km'],
            'duracion_min' => $calculada['minutos'],
            'geometria' => json_encode($calculada['puntos']),
            'calculada_en' => now(),
        ]);

        return $calculada + ['paradas' => $paradas, 'aproximada' => false];
    }

    /**
     * Las posiciones de la unidad del viaje desde el día de la carga (o del
     * último día, si no tiene fecha de carga).
     *
     * @return list<array{0: float, 1: float}>
     */
    public function recorrido(int $booking): array
    {
        $viaje = DB::table('booking')->where('booking_id', $booking)->first(['unidad_id', 'loading_EDT']);

        if ($viaje === null || $viaje->unidad_id === null) {
            return [];
        }

        $desde = $viaje->loading_EDT ? Carbon::parse($viaje->loading_EDT)->startOfDay() : now()->subDay();

        return DB::table('gps_posicion')
            ->where('unidad_id', $viaje->unidad_id)->where('fecha', '>=', $desde)
            ->orderByDesc('fecha')->limit(self::MAX_POSICIONES)
            ->get(['lat', 'lng'])
            ->reverse()
            ->map(fn ($p) => [(float) $p->lat, (float) $p->lng])
            ->values()->all();
    }

    /**
     * Origen, punto de carga, destino y punto de entrega, en ese orden, los que
     * tengan coordenadas y sin repetir el mismo lugar dos veces seguidas.
     *
     * @return list<array{nombre: string, lat: float, lng: float}>
     */
    private function paradas(int $booking): array
    {
        $b = DB::table('booking as b')
            ->leftJoin('loading_ports as o', 'o.port_id', '=', 'b.loading_port')
            ->leftJoin('pickup_place as c', 'c.pick_id', '=', 'b.pick_up_place_id')
            ->leftJoin('dicharge_port as d', 'd.dicharge_port_id', '=', 'b.dicharge_port_id')
            ->leftJoin('final_destination as e', 'e.final_destination_id', '=', 'b.final_destination_id')
            ->where('b.booking_id', $booking)
            ->first([
                'o.port_name as o_nombre', 'o.latitud as o_lat', 'o.longitud as o_lng',
                'c.name as c_nombre', 'c.latitud as c_lat', 'c.longitud as c_lng',
                'd.name as d_nombre', 'd.latitud as d_lat', 'd.longitud as d_lng',
                'e.name as e_nombre', 'e.latitud as e_lat', 'e.longitud as e_lng',
            ]);

        if ($b === null) {
            return [];
        }

        $paradas = [];

        foreach (['o', 'c', 'd', 'e'] as $p) {
            if ($b->{$p.'_lat'} === null || $b->{$p.'_lng'} === null) {
                continue;
            }

            $punto = ['nombre' => (string) $b->{$p.'_nombre'}, 'lat' => (float) $b->{$p.'_lat'}, 'lng' => (float) $b->{$p.'_lng'}];
            $anterior = end($paradas);

            if ($anterior === false || [$anterior['lat'], $anterior['lng']] !== [$punto['lat'], $punto['lng']]) {
                $paradas[] = $punto;
            }
        }

        return $paradas;
    }

    /**
     * @param  list<array{nombre: string, lat: float, lng: float}>  $paradas
     * @return array{puntos: list<array{0: float, 1: float}>, km: ?float, minutos: ?int}|null
     */
    private function calcula(string $proveedor, array $paradas): ?array
    {
        $lngLat = array_map(fn ($p) => [$p['lng'], $p['lat']], $paradas);

        try {
            if ($proveedor === 'ors' && config('gps.rutas.ors_key')) {
                $r = Http::timeout(15)->withHeaders(['Authorization' => (string) config('gps.rutas.ors_key')])
                    ->post('https://api.openrouteservice.org/v2/directions/driving-hgv/geojson', ['coordinates' => $lngLat])
                    ->throw()->json('features.0');

                return $this->resultado($r['geometry']['coordinates'] ?? [], $r['properties']['summary']['distance'] ?? null, $r['properties']['summary']['duration'] ?? null);
            }

            if ($proveedor === 'osrm') {
                $puntos = implode(';', array_map(fn ($c) => $c[0].','.$c[1], $lngLat));
                $r = Http::timeout(15)
                    ->get(rtrim((string) config('gps.rutas.osrm_url'), '/').'/route/v1/driving/'.$puntos, ['overview' => 'full', 'geometries' => 'geojson'])
                    ->throw()->json('routes.0');

                return $this->resultado($r['geometry']['coordinates'] ?? [], $r['distance'] ?? null, $r['duration'] ?? null);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $coordenadas  [lng, lat], como las da GeoJSON
     * @return array{puntos: list<array{0: float, 1: float}>, km: ?float, minutos: ?int}|null
     */
    private function resultado(array $coordenadas, ?float $metros, ?float $segundos): ?array
    {
        if (count($coordenadas) < 2) {
            return null;
        }

        // Miles de puntos no se distinguen en el mapa y viajan en cada refresco:
        // se toma uno de cada tantos, conservando siempre el último.
        $paso = (int) ceil(count($coordenadas) / self::MAX_PUNTOS_RUTA);
        $ultimo = end($coordenadas);
        $coordenadas = array_values(array_filter($coordenadas, fn ($i) => $i % $paso === 0, ARRAY_FILTER_USE_KEY));
        if (end($coordenadas) !== $ultimo) {
            $coordenadas[] = $ultimo;
        }

        return [
            'puntos' => array_map(fn ($c) => [round((float) $c[1], 5), round((float) $c[0], 5)], $coordenadas),
            'km' => $metros === null ? null : round($metros / 1000, 1),
            'minutos' => $segundos === null ? null : (int) round($segundos / 60),
        ];
    }
}
