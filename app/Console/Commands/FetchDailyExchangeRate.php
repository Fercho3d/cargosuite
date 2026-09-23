<?php

namespace App\Console\Commands;

use App\Support\ExchangeRates;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Registra el tipo de cambio del dólar de hoy, si aún no está.
 *
 * Es lo que en Yii2 hacía `Exchange::check()` al vuelo cada vez que alguien
 * guardaba una transacción. Programado (`routes/console.php`, lunes a viernes
 * a las 07:30) el dato ya está cuando empieza el día; las transacciones lo
 * siguen pidiendo por si un día falló.
 *
 *   php artisan exchange:diario
 */
class FetchDailyExchangeRate extends Command
{
    protected $signature = 'exchange:diario';

    protected $description = 'Registra el tipo de cambio del dólar de hoy desde Banxico';

    public function handle(ExchangeRates $tipos): int
    {
        $hoy = Carbon::today();

        if ($tipos->ensureFor($hoy)) {
            $this->info('Tipo de cambio del dólar registrado para el '.$hoy->format('d/m/Y').'.');

            return self::SUCCESS;
        }

        $this->error('Banxico no devolvió tipo de cambio para el '.$hoy->format('d/m/Y').' (ver el registro de la aplicación).');

        return self::FAILURE;
    }
}
