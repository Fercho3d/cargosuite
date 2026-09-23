<?php

namespace App\Console\Commands;

use App\Actions\Transactions\RefreshCancellationStatus;
use App\Models\CfdiCancelacion;
use App\Models\Core\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Revisa ante el SAT las cancelaciones que quedaron pendientes.
 *
 * El PAC avisa una sola vez, cuando recibe la solicitud. Que el receptor
 * autorice, que la rechace o que se le venza el plazo de 72 horas solo lo sabe
 * el SAT, así que hay que volver a preguntar: sin esto, una factura cancelada
 * con aceptación se quedaría marcada «en proceso» para siempre.
 *
 * Programado en `routes/console.php` a diario a las 08:00.
 *
 *   php artisan cfdi:revisar-cancelaciones
 *   php artisan cfdi:revisar-cancelaciones --pausa=0   (sin esperar entre consultas)
 */
class ReviewCfdiCancellations extends Command
{
    protected $signature = 'cfdi:revisar-cancelaciones {--pausa=1 : Segundos de espera entre consultas al SAT}';

    protected $description = 'Pregunta al SAT en qué quedaron las cancelaciones solicitadas';

    public function handle(RefreshCancellationStatus $consultar): int
    {
        $pendientes = CfdiCancelacion::pendientes()->orderBy('transc_id')->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay cancelaciones pendientes de confirmar.');

            return self::SUCCESS;
        }

        $pausa = (int) $this->option('pausa');
        $confirmadas = 0;

        foreach ($pendientes as $indice => $solicitud) {
            // Una pausa entre consultas: el servicio del SAT es público y
            // gratuito, y no hay por qué castigarlo con una ráfaga.
            if ($indice > 0 && $pausa > 0) {
                sleep($pausa);
            }

            $transaccion = Transaction::find($solicitud->transc_id);

            if ($transaccion === null) {
                $this->warn("La transacción {$solicitud->transc_id} ya no existe; se omite.");

                continue;
            }

            $consulta = $consultar->handle($transaccion);

            if (! $consulta->seConsulto()) {
                $this->warn("{$solicitud->uuid}: {$consulta->motivo}");
                Log::warning('No se pudo revisar una cancelación', [
                    'transc_id' => $solicitud->transc_id,
                    'motivo' => $consulta->motivo,
                ]);

                continue;
            }

            $confirmadas += $consulta->estaCancelado() ? 1 : 0;

            $this->line("{$solicitud->uuid}: {$consulta->estado} ".trim((string) $consulta->estatusCancelacion));

            Log::info('Cancelación revisada ante el SAT', [
                'transc_id' => $solicitud->transc_id,
                'uuid' => $solicitud->uuid,
                'sat_estado' => $consulta->estado,
                'sat_estatus' => $consulta->estatusCancelacion,
            ]);
        }

        $this->info("Revisadas {$pendientes->count()} solicitudes; {$confirmadas} ya están canceladas ante el SAT.");

        return self::SUCCESS;
    }
}
