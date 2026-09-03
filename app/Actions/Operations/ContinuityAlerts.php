<?php

namespace App\Actions\Operations;

use App\Mail\ContinuityAlertMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por correo de las tareas del booking que siguen sin marcarse.
 *
 * Porta las dos acciones de cron de `BookingContinuityController` en Yii2:
 * `actionNotification` (todavía no llega la fecha estimada) y `actionDeadline`
 * (ya se cumplió o se pasó). Las dos recorren lo mismo y solo cambian en esa
 * comparación, así que aquí son un método con un nivel.
 *
 * Los avisos no van al cliente: van al correo de soporte que lleva la operación.
 *
 * Diferencia de forma, no de fondo: el original consultaba la continuidad y la
 * lista de verificación **una vez por booking** (unas diez mil consultas por
 * corrida sobre la base de hoy). Aquí las tres tablas se traen de una vez y el
 * recorrido es en memoria; los correos que salen son los mismos.
 */
class ContinuityAlerts
{
    /**
     * Los hitos que se vigilan, tal como los lista `BookingContinuity::getTaksFields()`.
     *
     * ⚠️ «Gated Out» aparece **dos veces** en el original, así que ese hito
     * manda dos correos iguales. Se conserva la lista tal cual.
     *
     * @var list<array{label: string, field: string}>
     */
    private const HITOS = [
        ['label' => 'Empty Pass', 'field' => 'vacuum_maneuver'],
        ['label' => 'Gated Out', 'field' => 'gated_out'],
        ['label' => 'Port Closing Day', 'field' => 'doc_cut_of'],
        ['label' => 'Gated Out', 'field' => 'gated_out'],
        ['label' => 'SI', 'field' => 'SI_date'],
        ['label' => 'Draft Customer', 'field' => 'draf_client'],
        ['label' => 'Gated In', 'field' => 'gated_IN'],
        ['label' => 'Cleared', 'field' => 'cleared'],
        ['label' => 'Corrected Draft', 'field' => 'corrected_draft'],
        ['label' => 'Departure', 'field' => 'departure'],
        ['label' => 'BL Payment', 'field' => 'bl_payment'],
        ['label' => 'SWB', 'field' => 'swb'],
        ['label' => 'Delivered', 'field' => 'delivered'],
    ];

    /**
     * @param  bool  $enviar  En falso solo devuelve la lista, sin mandar correo.
     * @return list<array{booking: string, field: string, label: string, date: string}>
     */
    public function handle(string $nivel, bool $enviar = true): array
    {
        $hoy = now()->startOfDay();
        $avisos = [];

        foreach ($this->bookings() as $fila) {
            foreach ($this->pending($fila, $nivel, $hoy) as $aviso) {
                $avisos[] = $aviso;
            }
        }

        if ($enviar) {
            foreach ($avisos as $aviso) {
                Mail::to(config('marca.correo.avisos_operacion'))->send(new ContinuityAlertMail(
                    $aviso['booking'],
                    $aviso['label'],
                    $aviso['date'],
                    $nivel,
                ));
            }
        }

        return $avisos;
    }

    /**
     * Los hitos de un booking que tocan aviso: tienen fecha estimada, no están
     * marcados en la lista de verificación y caen del lado del nivel que se pide.
     *
     * @return list<array{booking: string, field: string, label: string, date: string}>
     */
    private function pending(object $fila, string $nivel, Carbon $hoy): array
    {
        $vencido = fn (string $fecha) => $hoy->greaterThanOrEqualTo(Carbon::parse($fecha)->startOfDay());

        // Un booking sin lista de verificación se avisa por el primer hito y no
        // se revisa más, igual que en el original.
        if ($fila->check_id === null) {
            $primero = self::HITOS[0];

            return blank($fila->{$primero['field']})
                ? []
                : [$this->alert($fila, $primero)];
        }

        $avisos = [];

        foreach (self::HITOS as $hito) {
            $estimada = $fila->{$hito['field']};

            if (blank($estimada) || filled($fila->{$hito['field'].'_chk_date'})) {
                continue;
            }

            if ($vencido($estimada) === ($nivel === ContinuityAlertMail::VENCIDO)) {
                $avisos[] = $this->alert($fila, $hito);
            }
        }

        return $avisos;
    }

    /** @param  array{label: string, field: string}  $hito */
    private function alert(object $fila, array $hito): array
    {
        return [
            'booking' => (string) $fila->booking_number,
            'field' => $hito['field'],
            'label' => $hito['label'],
            'date' => (string) $fila->{$hito['field']},
        ];
    }

    /**
     * Bookings con su continuidad y su lista de verificación, en una consulta.
     *
     * @return Collection<int, object>
     */
    private function bookings()
    {
        $hitos = collect(self::HITOS)->pluck('field')->unique();

        $columnas = $hitos->map(fn (string $campo) => "bc.{$campo}")
            ->merge($hitos->map(fn (string $campo) => "cl.{$campo}_chk_date"))
            ->push('b.booking_id')
            ->push('b.booking_number')
            ->push('cl.check_id')
            ->all();

        return DB::table('booking as b')
            ->join('booking_continuity as bc', 'bc.booking', '=', 'b.booking_id')
            ->leftJoin('check_list as cl', 'cl.booking', '=', 'b.booking_id')
            ->orderBy('b.booking_id')
            ->get($columnas);
    }
}
