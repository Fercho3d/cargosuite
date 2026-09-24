<?php

namespace App\Support\Payroll;

use App\Support\Settlements\DriverSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina interna: qué se le paga a cada quien en un periodo.
 *
 * Reúne sueldos, viajes, bonos y descuentos y, según el régimen de cada
 * empleado, sus impuestos y cuotas (ver `Impuestos`). Lo patronal se guarda
 * con tipo `patronal`: es costo de la empresa y no toca el neto. El CFDI de
 * nómina lo timbra `StampPayslip`.
 */
class Payroll
{
    /** @return Collection<int, object> */
    public static function empleadosActivos(string $periodicidad): Collection
    {
        return DB::table('empleado')->where('activo', 1)
            // Sin periodicidad propia entra a todas, como antes de tenerla.
            ->where(fn ($q) => $q->whereNull('periodicidad')->orWhere('periodicidad', '')->orWhere('periodicidad', $periodicidad))
            ->orderBy('nombre')->get();
    }

    /**
     * Rehace los impuestos y cuotas de la nómina sobre lo que hay capturado.
     *
     * Se borra lo automático y se vuelve a sacar, así que un bono agregado o un
     * renglón quitado mueven el ISR sin que nadie tenga que acordarse.
     */
    public static function recalcula(int $nomina): void
    {
        $fila = DB::table('nomina')->where('nomina_id', $nomina)->first();

        // A quien ya se le pagó no se le mueve nada: su dinero ya salió.
        $pagados = self::pagados($nomina);

        DB::table('nomina_renglon')->where('nomina_id', $nomina)->where('automatico', 1)
            ->whereNotIn('empleado_id', $pagados)->delete();

        $gravados = DB::table('nomina_renglon')->where('nomina_id', $nomina)->where('tipo', 'percepcion')
            ->whereNotIn('empleado_id', $pagados)
            ->groupBy('empleado_id')->selectRaw('empleado_id, SUM(importe) as total')->pluck('total', 'empleado_id');

        foreach (DB::table('empleado')->whereIn('empleado_id', $gravados->keys())->get() as $empleado) {
            $renglones = Impuestos::renglones($empleado, (float) $gravados[$empleado->empleado_id], self::dias($fila->desde, $fila->hasta), $fila->hasta);

            foreach ($renglones as $renglon) {
                DB::table('nomina_renglon')->insert($renglon + [
                    'nomina_id' => $nomina, 'empleado_id' => $empleado->empleado_id, 'automatico' => true,
                ]);
            }
        }
    }

    /**
     * Empleados de la nómina a los que ya se les pagó por separado.
     *
     * @return Collection<int, int>
     */
    public static function pagados(int $nomina): Collection
    {
        return DB::table('nomina_recibo')->where('nomina_id', $nomina)->whereNotNull('pagado_en')->pluck('empleado_id');
    }

    /**
     * Días del periodo, ambos extremos incluidos.
     *
     * Se cuenta así y no con `diffInDays` porque una quincena del 1 al 15 son
     * quince días, no catorce, y ese día de menos aparece en todos los recibos.
     */
    public static function dias(string $desde, string $hasta): int
    {
        return (int) Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) + 1;
    }

    /**
     * Lo que se propone pagarle a un empleado en el periodo.
     *
     * Dos fuentes: su sueldo por los días del periodo, y —si es operador— lo que
     * ya se le liquidó por sus viajes y todavía no ha entrado a ninguna nómina.
     * Esa segunda parte es la que evita capturar dos veces lo mismo.
     *
     * @return list<array{concepto: string, tipo: string, importe: float, liquidacion_id: ?int}>
     */
    public static function propuesta(object $empleado, string $desde, string $hasta): array
    {
        $renglones = [];

        $salario = (float) ($empleado->salario_diario ?? 0);

        if ($salario > 0) {
            $dias = self::dias($desde, $hasta);
            $renglones[] = [
                'concepto' => __('Sueldo').' · '.$dias.' '.__('días'),
                'tipo' => 'percepcion',
                'importe' => round($salario * $dias, 2),
                'liquidacion_id' => null,
            ];
        }

        foreach (self::liquidacionesPendientes($empleado, $desde, $hasta) as $liquidacion) {
            $renglones[] = [
                'concepto' => __('Viajes').' · '.$liquidacion->numero,
                'tipo' => 'percepcion',
                'importe' => $liquidacion->total,
                'liquidacion_id' => (int) $liquidacion->liquidacion_id,
            ];
        }

        return $renglones;
    }

    /**
     * Liquidaciones de viaje del operador que aún no entraron a una nómina.
     *
     * @return Collection<int, object>
     */
    public static function liquidacionesPendientes(object $empleado, string $desde, string $hasta): Collection
    {
        if (empty($empleado->operador_id) || ! Schema::hasTable('liquidacion')) {
            return collect();
        }

        return DB::table('liquidacion as l')
            ->where('l.operador_id', $empleado->operador_id)
            ->whereBetween('l.hasta', [$desde, $hasta])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('nomina_renglon as nr')
                ->whereColumn('nr.liquidacion_id', 'l.liquidacion_id'))
            ->get(['l.liquidacion_id', 'l.numero'])
            ->map(function (object $l) {
                $l->total = DriverSettlement::totales((int) $l->liquidacion_id)['total'];

                return $l;
            })
            ->filter(fn (object $l) => $l->total > 0)
            ->values();
    }

    /**
     * Totales por empleado de una nómina.
     *
     * @return Collection<int, object>
     */
    public static function porEmpleado(int $nomina): Collection
    {
        return DB::table('nomina_renglon as r')
            ->join('empleado as e', 'e.empleado_id', '=', 'r.empleado_id')
            ->where('r.nomina_id', $nomina)
            ->groupBy('e.empleado_id', 'e.nombre', 'e.numero', 'e.puesto', 'e.clabe')
            ->orderBy('e.nombre')
            ->get([
                'e.empleado_id', 'e.nombre', 'e.numero', 'e.puesto', 'e.clabe',
                DB::raw("SUM(CASE WHEN r.tipo = 'percepcion' THEN r.importe ELSE 0 END) as percepciones"),
                DB::raw("SUM(CASE WHEN r.tipo = 'deduccion' THEN r.importe ELSE 0 END) as deducciones"),
                DB::raw("SUM(CASE WHEN r.tipo = 'patronal' THEN r.importe ELSE 0 END) as patronal"),
            ])
            ->map(function (object $fila) {
                $fila->percepciones = round((float) $fila->percepciones, 2);
                $fila->deducciones = round((float) $fila->deducciones, 2);
                $fila->patronal = round((float) $fila->patronal, 2);
                $fila->neto = round($fila->percepciones - $fila->deducciones, 2);

                return $fila;
            });
    }

    /** @return array{percepciones: float, deducciones: float, neto: float, patronal: float, empleados: int} */
    public static function totales(int $nomina): array
    {
        $porEmpleado = self::porEmpleado($nomina);

        return [
            'percepciones' => round((float) $porEmpleado->sum('percepciones'), 2),
            'deducciones' => round((float) $porEmpleado->sum('deducciones'), 2),
            'neto' => round((float) $porEmpleado->sum('neto'), 2),
            'patronal' => round((float) $porEmpleado->sum('patronal'), 2),
            'empleados' => $porEmpleado->count(),
        ];
    }

    public static function siguienteNumero(): string
    {
        return 'NOM-'.str_pad((string) ((int) DB::table('nomina')->max('nomina_id') + 1), 5, '0', STR_PAD_LEFT);
    }
}
