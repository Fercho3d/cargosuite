<?php

namespace App\Support;

use App\Models\Core\Exchange;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alta del tipo de cambio del día, tal como lo hace `Exchange::check()` en Yii2.
 *
 * Fuente: la serie SF60653 del SIE de Banxico (dólar FIX por fecha de
 * liquidación). Su valor para el día X es exactamente el que el sistema tomaba
 * del DOF para X: el último publicado **antes** de X. Esa es la convención
 * contable del sistema; comprobado fecha por fecha contra el indicador 158 del
 * DOF, que se dejó de usar cuando su certificado venció (19/09/2026).
 *
 * Si el servicio no responde, el error se registra y se sigue: guardar una
 * transacción no puede depender de que Banxico esté disponible, igual que en el
 * sistema original. Lo que sí se hace es recordar la falla (`failure()`) para
 * que el menú avise a todos hasta que Banxico vuelva a contestar.
 */
class ExchangeRates
{
    /** Serie de Banxico: tipo de cambio USD FIX por fecha de liquidación. */
    private const SERIE_USD_FIX = 'SF60653';

    /** Cuenta a la que pertenece ese tipo de cambio. */
    private const CUENTA_USD = 2;

    private const TIEMPO_LIMITE = 8;

    private const CLAVE_FALLA = 'banxico.falla';

    /** 'token' si Banxico lo rechazó, 'conexion' si no contestó; null si todo bien. */
    public static function failure(): ?string
    {
        return Cache::get(self::CLAVE_FALLA);
    }

    /**
     * @return bool Si al terminar hay un tipo de cambio del dólar para la fecha.
     *              Se busca por fecha **y** moneda: un euro capturado a mano ese
     *              día no cuenta como si ya estuviera el dólar.
     */
    public function ensureFor(Carbon $fecha): bool
    {
        if (Exchange::whereDate('date_exchange', $fecha)->where('account', self::CUENTA_USD)->exists()) {
            return true;
        }

        // Fechas futuras: todavía no hay nada publicado que registrar.
        if ($fecha->isAfter(Carbon::tomorrow())) {
            return false;
        }

        try {
            $registrado = $this->fetchAndStore($fecha);
            Cache::forget(self::CLAVE_FALLA);

            return $registrado;
        } catch (Throwable $e) {
            // Banxico contesta 400 con {"error":{"mensaje":"Token inválido"}}.
            $tokenRechazado = $e instanceof RequestException
                && str_contains(mb_strtolower((string) $e->response->json('error.mensaje')), 'token');
            // Caduca sola por si el token se corrige en un día que ya tenía su tipo de cambio.
            Cache::put(self::CLAVE_FALLA, $tokenRechazado ? 'token' : 'conexion', now()->addHours(12));

            Log::warning('No se pudo obtener el tipo de cambio de Banxico', [
                'fecha' => $fecha->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function fetchAndStore(Carbon $fecha): bool
    {
        $url = sprintf(
            'https://www.banxico.org.mx/SieAPIRest/service/v1/series/%s/datos/%s/%s',
            self::SERIE_USD_FIX,
            $fecha->toDateString(),
            $fecha->toDateString(),
        );

        $dato = Http::timeout(self::TIEMPO_LIMITE)
            ->withHeaders(['Bmx-Token' => (string) config('services.banxico.token')])
            ->get($url)
            ->throw()
            ->json('bmx.series.0.datos.0');

        if (! is_numeric($dato['dato'] ?? null)) {
            return false;
        }

        try {
            // `created_at`/`modified_at` son `date` en la base; el alta es del sistema, sin usuario.
            Exchange::create([
                'exchange_value' => $dato['dato'],
                'date_exchange' => $fecha->toDateString(),
                'taken_date' => Carbon::createFromFormat('d/m/Y', $dato['fecha'])->toDateString(),
                'account' => self::CUENTA_USD,
                'url' => $url,
                'created_at' => now()->toDateString(),
                'modified_at' => now()->toDateString(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra petición lo registró mientras se consultaba a Banxico (`uq_date`):
            // ya está, que es lo que se buscaba. No es una falla de Banxico.
        }

        return true;
    }
}
