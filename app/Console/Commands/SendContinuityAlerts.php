<?php

namespace App\Console\Commands;

use App\Actions\Operations\ContinuityAlerts;
use App\Mail\ContinuityAlertMail;
use Illuminate\Console\Command;

/**
 * Manda los avisos de tareas del booking sin marcar.
 *
 * En Yii2 esto eran dos direcciones web (`booking-continuity/notification` y
 * `booking-continuity/deadline`) que alguien llamaba desde un cron: cualquiera
 * que diera con la dirección disparaba los correos. Aquí es un comando, así que
 * solo se puede lanzar desde el servidor.
 *
 *   php artisan operacion:avisos-continuidad aviso     # aún no llega la fecha
 *   php artisan operacion:avisos-continuidad vencido   # ya se pasó
 *
 * Con `--simular` no manda nada y solo enseña qué saldría.
 */
class SendContinuityAlerts extends Command
{
    protected $signature = 'operacion:avisos-continuidad
                            {nivel=aviso : «aviso» (antes de la fecha) o «vencido» (ya pasada)}
                            {--simular : Enseña los avisos sin mandar correo}';

    protected $description = 'Avisa por correo de las tareas del booking que siguen sin marcarse';

    public function handle(ContinuityAlerts $avisos): int
    {
        $nivel = (string) $this->argument('nivel');

        if (! in_array($nivel, [ContinuityAlertMail::AVISO, ContinuityAlertMail::VENCIDO], true)) {
            $this->error('El nivel tiene que ser «aviso» o «vencido».');

            return self::FAILURE;
        }

        $simular = (bool) $this->option('simular');

        // `Mail::to([])` revienta: sin destinatarios no hay a quién avisar, y el
        // cron no debe marcar error por una instalación sin ese correo.
        if (! $simular && config('marca.correo.avisos_operacion') === []) {
            $this->warn('No se mandó ningún aviso: falta el destinatario en MARCA_MAIL_AVISOS.');

            return self::SUCCESS;
        }

        $resultado = $avisos->handle($nivel, enviar: ! $simular);

        if ($resultado === []) {
            $this->info('No hay tareas que avisar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Booking', 'Tarea', 'Fecha estimada'],
            array_map(fn (array $aviso) => [$aviso['booking'], $aviso['label'], $aviso['date']], $resultado),
        );

        $this->info(($simular ? 'Se mandarían ' : 'Se mandaron ').count($resultado).' avisos.');

        return self::SUCCESS;
    }
}
