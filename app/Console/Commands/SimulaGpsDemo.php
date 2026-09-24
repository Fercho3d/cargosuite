<?php

namespace App\Console\Commands;

use App\Support\Gps\SimuladorDemo;
use Illuminate\Console\Command;

/** Mueve las unidades de la demostración. Solo corre donde `MARCA_DEMO=true`. */
class SimulaGpsDemo extends Command
{
    protected $signature = 'gps:simula-demo';

    protected $description = 'Mueve las unidades con GPS de la demostración';

    public function handle(SimuladorDemo $simulador): int
    {
        if (! config('marca.demo')) {
            $this->warn('Esta instalación no es de demostración: no se simula nada.');

            return self::SUCCESS;
        }

        $this->info($simulador->avanza().' unidades movidas.');

        return self::SUCCESS;
    }
}
