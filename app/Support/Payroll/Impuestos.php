<?php

namespace App\Support\Payroll;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Impuestos y cuotas de un empleado en un periodo, según su régimen.
 *
 * Todo número sale de `tabla_fiscal` y `parametro_fiscal` del año del periodo,
 * que se editan en Catálogos: cambian cada año y quien los conoce es el
 * contador del cliente, no el código.
 *
 * - **Sueldos y salarios**: ISR (tarifa del art. 96 con subsidio al empleo),
 *   cuotas obreras del IMSS y crédito INFONAVIT; y, aparte, lo patronal.
 * - **Asimilados**: ISR sin subsidio, sin IMSS.
 * - **Honorarios**: IVA trasladado y retenciones de ISR e IVA.
 *
 * ⚠️ Toda percepción se toma gravada (no separa exentos de horas extra,
 * aguinaldo, etc.). El CFDI de nómina se timbra en `StampPayslip`.
 *
 * La tarifa es la mensual, proporcional por días (base / días × 30.4): así
 * salen las del Anexo 8 para 7, 10 y 15 días, y un solo juego de tablas sirve
 * a cualquier periodicidad.
 */
class Impuestos
{
    /** Días promedio del mes que usa el SAT para las tarifas por periodo. */
    private const MES = 30.4;

    /**
     * @return list<array{concepto: string, tipo: string, importe: float}>
     */
    public static function renglones(object $empleado, float $gravado, int $dias, string $hasta): array
    {
        [$anio, $p] = self::parametros($hasta);

        if ($anio === 0 || $gravado <= 0 || $dias <= 0) {
            return [];
        }

        $renglones = match ($empleado->regimen ?? 'ninguno') {
            'sueldos' => [
                self::renglon(__('ISR'), 'deduccion', self::isr($anio, $p, $gravado, $dias, subsidio: true)),
                ...self::imss($anio, $p, $empleado, $dias, $hasta),
                self::renglon(__('Crédito INFONAVIT'), 'deduccion', (float) ($empleado->infonavit_descuento ?? 0)),
            ],
            'asimilados' => [self::renglon(__('ISR'), 'deduccion', self::isr($anio, $p, $gravado, $dias, subsidio: false))],
            'honorarios' => [
                self::renglon(__('IVA').' '.$p['honorarios_iva'].' %', 'percepcion', $gravado * $p['honorarios_iva'] / 100),
                self::renglon(__('Retención de ISR'), 'deduccion', $gravado * $p['honorarios_retencion_isr'] / 100),
                self::renglon(__('Retención de IVA'), 'deduccion', $gravado * $p['honorarios_retencion_iva'] / 100),
            ],
            default => [],
        };

        return array_values(array_filter($renglones, fn (array $r) => $r['importe'] > 0));
    }

    /**
     * Subsidio al empleo que le corresponde al periodo, lo alcance o no a usar
     * contra el ISR. El CFDI de nómina lo declara como «subsidio causado».
     */
    public static function subsidioCausado(float $gravado, int $dias, string $hasta): float
    {
        [$anio, $p] = self::parametros($hasta);

        if ($anio === 0 || $dias <= 0 || $gravado / $dias * self::MES > $p['subsidio_limite']) {
            return 0;
        }

        return round($p['uma_diaria'] * $dias * $p['subsidio_porcentaje'] / 100, 2);
    }

    /**
     * Salario diario integrado con el que cotiza al IMSS, ya topado. Es el que
     * pide el CFDI de nómina en `SalarioBaseCotApor` y `SalarioDiarioIntegrado`.
     */
    public static function salarioIntegrado(object $empleado, string $hasta): float
    {
        [$anio, $p] = self::parametros($hasta);
        $salario = (float) ($empleado->salario_diario ?? 0);

        if ($anio === 0 || $salario <= 0) {
            return round($salario, 2);
        }

        return round(min($salario * self::factorDeIntegracion($p, $empleado->ingreso ?? null, $hasta), $p['tope_sbc_umas'] * $p['uma_diaria']), 2);
    }

    /** Año de tablas vigente para la fecha y sus parámetros. */
    private static function parametros(string $hasta): array
    {
        $anio = (int) DB::table('parametro_fiscal')->where('anio', '<=', (int) substr($hasta, 0, 4))->max('anio');

        return [$anio, DB::table('parametro_fiscal')->where('anio', $anio)->pluck('valor', 'clave')->map(fn ($v) => (float) $v)];
    }

    private static function isr(int $anio, $p, float $gravado, int $dias, bool $subsidio): float
    {
        $mensual = $gravado / $dias * self::MES;

        $tramo = DB::table('tabla_fiscal')->where(['anio' => $anio, 'tabla' => 'isr_mensual'])
            ->where('limite_inferior', '<=', $mensual)->orderByDesc('limite_inferior')->first();

        if ($tramo === null) {
            return 0;
        }

        $isr = (float) $tramo->cuota_fija + ($mensual - (float) $tramo->limite_inferior) * (float) $tramo->porcentaje / 100;

        // Desde 2024 el subsidio solo reduce el ISR: ya no se paga saldo a favor.
        if ($subsidio && $mensual <= $p['subsidio_limite']) {
            $isr = max(0, $isr - $p['uma_diaria'] * self::MES * $p['subsidio_porcentaje'] / 100);
        }

        return $isr * $dias / self::MES;
    }

    /** @return list<array{concepto: string, tipo: string, importe: float}> */
    private static function imss(int $anio, $p, object $empleado, int $dias, string $hasta): array
    {
        $salario = (float) ($empleado->salario_diario ?? 0);

        if ($salario <= 0) {
            return [];
        }

        $uma = $p['uma_diaria'];
        $sbc = min($salario * self::factorDeIntegracion($p, $empleado->ingreso ?? null, $hasta), $p['tope_sbc_umas'] * $uma);
        $excedente = max(0, $sbc - 3 * $uma);
        $pct = fn (float $base, string ...$claves) => $base * $dias * array_sum(array_map(fn ($c) => $p[$c], $claves)) / 100;

        $obrero = $pct($excedente, 'imss_excedente_obrero')
            + $pct($sbc, 'imss_dinero_obrero', 'imss_pensionados_obrero', 'imss_invalidez_obrero', 'cesantia_obrero');

        // Art. 36 LSS: con salario mínimo, las cuotas obreras las paga el patrón.
        $minimo = $salario <= $p['salario_minimo'];

        $cesantia = (float) DB::table('tabla_fiscal')->where(['anio' => $anio, 'tabla' => 'cesantia_patronal'])
            ->where('limite_inferior', '<=', $minimo ? 0 : $sbc / $uma)->orderByDesc('limite_inferior')->value('porcentaje');

        return [
            self::renglon(__('IMSS obrero'), 'deduccion', $minimo ? 0 : $obrero),
            self::renglon(__('IMSS patronal'), 'patronal', $uma * $dias * $p['imss_cuota_fija'] / 100
                + $pct($excedente, 'imss_excedente_patronal')
                + $pct($sbc, 'imss_dinero_patronal', 'imss_pensionados_patronal', 'imss_invalidez_patronal', 'imss_riesgo_trabajo', 'imss_guarderias')
                + ($minimo ? $obrero : 0)),
            self::renglon(__('Retiro, cesantía y vejez'), 'patronal', $sbc * $dias * ($p['retiro'] + $cesantia) / 100),
            self::renglon(__('INFONAVIT').' '.$p['infonavit'].' %', 'patronal', $pct($sbc, 'infonavit')),
        ];
    }

    /**
     * Factor de integración del salario base de cotización: aguinaldo y prima
     * vacacional repartidos en el año. Las vacaciones son las de la reforma de
     * 2023 según los años cumplidos al cierre del periodo.
     */
    private static function factorDeIntegracion($p, ?string $ingreso, string $hasta): float
    {
        $anios = $ingreso ? (int) Carbon::parse($ingreso)->diffInYears(Carbon::parse($hasta)) + 1 : 1;

        $vacaciones = match (true) {
            $anios <= 5 => 10 + 2 * $anios,
            default => 22 + 2 * intdiv($anios - 6, 5),
        };

        return 1 + ($p['aguinaldo_dias'] + $vacaciones * $p['prima_vacacional'] / 100) / 365;
    }

    /** @return array{concepto: string, tipo: string, importe: float} */
    private static function renglon(string $concepto, string $tipo, float $importe): array
    {
        return ['concepto' => $concepto, 'tipo' => $tipo, 'importe' => round($importe, 2)];
    }
}
