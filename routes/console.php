<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Avisos de tareas del booking sin marcar, una vez al día.
 *
 * **Apagado por omisión.** Los mismos avisos se ven en la bandeja de la
 * aplicación (`/avisos`), que no le llena el buzón a nadie; el correo es un
 * extra que hay que pedir a propósito con `MARCA_AVISOS_CORREO=true`.
 *
 * Ojo antes de encenderlo en producción: la primera corrida manda **todo lo
 * vencido acumulado**, que hoy son unos 1,400 avisos de embarques viejos que
 * nadie cerró. Conviene acotarlo antes, como hace la bandeja.
 *
 * En Yii2 esto lo disparaba un cron externo llamando a dos direcciones web. Aquí
 * son dos comandos: primero los que todavía tienen tiempo y luego los que ya se
 * pasaron de fecha, con cinco minutos de diferencia para que los correos no
 * lleguen mezclados. La hora es la de la operación, no la del servidor —que
 * corre en UTC—, para que caigan a primera hora del día laboral.
 *
 * En el servidor hace falta la línea de cron que despierta al planificador:
 *   * * * * * cd /ruta/de/la/aplicacion && php8.4 artisan schedule:run >> /dev/null 2>&1
 */
/*
 * El dólar del día, de lunes a viernes a primera hora. Antes lo traía el primer
 * usuario que guardaba una transacción; así ya está cuando llega. Sábado y
 * domingo Banxico no publica, y si un día falla, la siguiente transacción lo
 * vuelve a intentar sola (`ExchangeRates::ensureFor()`).
 */
Schedule::command('exchange:diario')
    ->weekdays()
    ->dailyAt('07:30')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->description('Tipo de cambio del dólar (Banxico)');

/*
 * Cancelaciones de CFDI que quedaron esperando respuesta.
 *
 * El PAC avisa una sola vez, al recibir la solicitud; que el receptor la
 * autorice, la rechace o deje vencer el plazo de 72 horas solo lo sabe el SAT.
 * A diario, ya empezada la jornada, se le pregunta por cada solicitud pendiente
 * y las que ya se consumaron se marcan solas.
 */
Schedule::command('cfdi:revisar-cancelaciones')
    ->dailyAt('08:00')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->description('Estado ante el SAT de las cancelaciones solicitadas');

if (config('marca.correo.avisos_por_correo')) {
    Schedule::command('operacion:avisos-continuidad aviso')
        ->dailyAt('07:00')
        ->timezone('America/Mexico_City')
        ->withoutOverlapping()
        ->description('Tareas del booking que aún tienen tiempo');

    Schedule::command('operacion:avisos-continuidad vencido')
        ->dailyAt('07:05')
        ->timezone('America/Mexico_City')
        ->withoutOverlapping()
        ->description('Tareas del booking que ya se pasaron de fecha');
}
