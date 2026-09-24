<?php

namespace App\Http\Controllers\Api;

use App\Actions\Gps\RegistraPosicion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entrada de posiciones GPS. Ver `docs/GPS.md`.
 *
 * - `POST /api/gps/posiciones`: el JSON que reenvía un servidor Traccar
 *   (`forward.type=json`), que ya tradujo el protocolo de cada fabricante.
 * - `GET|POST /api/gps/osmand`: el protocolo OsmAnd, el de la app Traccar
 *   Client del celular y de algunos equipos que hablan HTTP.
 *
 * Traccar maneja la velocidad en nudos; aquí se guarda en km/h.
 */
class GpsController
{
    private const NUDO_KMH = 1.852;

    public function traccar(Request $request, RegistraPosicion $registra): JsonResponse
    {
        $this->autoriza($request);

        $datos = $request->validate([
            'device.uniqueId' => ['required', 'string', 'max:60'],
            'position.latitude' => ['required', 'numeric', 'between:-90,90'],
            'position.longitude' => ['required', 'numeric', 'between:-180,180'],
            'position.speed' => ['nullable', 'numeric', 'min:0'],
            'position.course' => ['nullable', 'numeric', 'between:0,360'],
            'position.fixTime' => ['nullable', 'date'],
        ]);

        $p = $datos['position'];

        return $this->respuesta($registra(
            (string) $datos['device']['uniqueId'],
            (float) $p['latitude'],
            (float) $p['longitude'],
            isset($p['speed']) ? (float) $p['speed'] * self::NUDO_KMH : null,
            isset($p['course']) ? (int) round((float) $p['course']) : null,
            isset($p['fixTime']) ? Carbon::parse($p['fixTime'])->setTimezone(config('app.timezone')) : now(),
        ));
    }

    public function osmand(Request $request, RegistraPosicion $registra): JsonResponse
    {
        $this->autoriza($request);

        $datos = $request->validate([
            'id' => ['required', 'string', 'max:60'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'bearing' => ['nullable', 'numeric', 'between:0,360'],
            'timestamp' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->respuesta($registra(
            (string) $datos['id'],
            (float) $datos['lat'],
            (float) $datos['lon'],
            isset($datos['speed']) ? (float) $datos['speed'] * self::NUDO_KMH : null,
            isset($datos['bearing']) ? (int) round((float) $datos['bearing']) : null,
            $this->fecha($datos['timestamp'] ?? null),
        ));
    }

    /** La clave va en la ruta, en `?token=` o en el encabezado `X-GPS-Token`. Sin clave configurada, nadie entra. */
    private function autoriza(Request $request): void
    {
        $esperada = (string) config('gps.token');
        $recibida = (string) ($request->route('token') ?? $request->header('X-GPS-Token') ?? $request->query('token', ''));

        abort_if($esperada === '' || ! hash_equals($esperada, $recibida), 401, 'Clave de GPS inválida.');
    }

    /** OsmAnd manda segundos (o milisegundos) desde 1970, o una fecha ISO. */
    private function fecha(?string $valor): Carbon
    {
        if ($valor === null || $valor === '') {
            return now();
        }

        if (ctype_digit($valor)) {
            $segundos = (int) $valor;

            return Carbon::createFromTimestampUTC($segundos > 100_000_000_000 ? intdiv($segundos, 1000) : $segundos)->setTimezone(config('app.timezone'));
        }

        return Carbon::parse($valor)->setTimezone(config('app.timezone'));
    }

    /** 202 al equipo desconocido: con un error, Traccar lo reintentaría sin fin. */
    private function respuesta(bool $guardada): JsonResponse
    {
        return response()->json(['guardada' => $guardada], $guardada ? 200 : 202);
    }
}
