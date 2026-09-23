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
 * ⚠️ **Calcula lo que se paga, no lo que se retiene.** Ni IMSS, ni INFONAVIT, ni
 * tablas de ISR, ni timbrado de CFDI de nómina: eso está regulado, cambia cada
 * año y es un producto aparte. Aquí se reúnen sueldos, viajes, bonos y
 * descuentos, sale el neto, y se **exporta** al sistema fiscal de la empresa.
 */
class Payroll
{
    /** @return Collection<int, object> */
    public static function empleadosActivos(): Collection
    {
        return DB::table('empleado')->where('activo', 1)->orderBy('nombre')->get();
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
            ])
            ->map(function (object $fila) {
                $fila->percepciones = round((float) $fila->percepciones, 2);
                $fila->deducciones = round((float) $fila->deducciones, 2);
                $fila->neto = round($fila->percepciones - $fila->deducciones, 2);

                return $fila;
            });
    }

    /** @return array{percepciones: float, deducciones: float, neto: float, empleados: int} */
    public static function totales(int $nomina): array
    {
        $porEmpleado = self::porEmpleado($nomina);

        return [
            'percepciones' => round((float) $porEmpleado->sum('percepciones'), 2),
            'deducciones' => round((float) $porEmpleado->sum('deducciones'), 2),
            'neto' => round((float) $porEmpleado->sum('neto'), 2),
            'empleados' => $porEmpleado->count(),
        ];
    }

    public static function siguienteNumero(): string
    {
        return 'NOM-'.str_pad((string) ((int) DB::table('nomina')->max('nomina_id') + 1), 5, '0', STR_PAD_LEFT);
    }
}
