<?php

namespace App\Support;

use App\Models\Core\Exchange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alta del tipo de cambio del día, tal como lo hace `Exchange::check()` en Yii2.
 *
 * Fuente: el indicador 158 (dólar FIX) del DOF/SIDOF. Se pide una ventana de 30
 * días que termina el **día anterior** a la fecha del documento y se toma el
 * último valor publicado. Esa es la convención contable del sistema: la fila que
 * "aplica el día X" contiene el tipo de cambio publicado antes de X. No hay que
 * tocarla — cambiarla movería números históricos.
 *
 * Si el servicio no responde, el error se registra y se sigue: guardar una
 * transacción no puede depender de que el DOF esté disponible, igual que en el
 * sistema original.
 */
class ExchangeRates
{
    /** Indicador del DOF: tipo de cambio USD FIX. */
    private const INDICADOR_USD_FIX = 158;

    /** Cuenta a la que pertenece ese tipo de cambio. */
    private const CUENTA_USD = 2;

    private const TIEMPO_LIMITE = 8;

    /** @return bool Si al terminar hay un tipo de cambio registrado para la fecha. */
    public function ensureFor(Carbon $fecha): bool
    {
        if (Exchange::whereDate('date_exchange', $fecha)->exists()) {
            return true;
        }

        // Fechas futuras: todavía no hay nada publicado que registrar.
        if ($fecha->isAfter(Carbon::tomorrow())) {
            return false;
        }

        try {
            return $this->fetchAndStore($fecha);
        } catch (Throwable $e) {
            Log::warning('No se pudo obtener el tipo de cambio del DOF', [
                'fecha' => $fecha->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function fetchAndStore(Carbon $fecha): bool
    {
        $url = sprintf(
            'https://sidofqa.segob.gob.mx/dof/sidof/indicadores/%d/%s/%s',
            self::INDICADOR_USD_FIX,
            $fecha->copy()->subDays(30)->format('d-m-Y'),
            $fecha->copy()->subDay()->format('d-m-Y'),
        );

        $indicadores = Http::timeout(self::TIEMPO_LIMITE)
            ->get($url)
            ->throw()
            ->json('ListaIndicadores');

        $ultimo = is_array($indicadores) ? end($indicadores) : false;

        if ($ultimo === false || ! isset($ultimo['valor'])) {
            return false;
        }

        Exchange::create([
            'exchange_value' => $ultimo['valor'],
            'date_exchange' => $fecha->toDateString(),
            'taken_date' => Carbon::parse($ultimo['fecha'])->toDateString(),
            'account' => self::CUENTA_USD,
            'url' => $url,
        ]);

        return true;
    }
}
