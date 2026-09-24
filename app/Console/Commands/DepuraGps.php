<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Borra las posiciones GPS más viejas que `gps.retencion_dias`. Corre cada noche. */
class DepuraGps extends Command
{
    protected $signature = 'gps:depura';

    protected $description = 'Borra el historial de posiciones GPS que pasó la retención';

    public function handle(): int
    {
        $borradas = DB::table('gps_posicion')
            ->where('fecha', '<', now()->subDays((int) config('gps.retencion_dias')))
            ->delete();

        $this->info("{$borradas} posiciones borradas.");

        return self::SUCCESS;
    }
}
