<?php

namespace App\Support\Cfdi;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Arma el layout del recibo de nómina (CFDI 4.0 + complemento Nómina 1.2) que
 * se le manda a Facturación Moderna.
 *
 * Es el formato `[ReciboNomina]` de su documentación (repositorio
 * `facturacionmoderna/Comprobantes`, `CFDI_40/CFDI40_ejemploNomina12RC.txt`):
 * el PAC pone el uso CN01, el régimen 605 del receptor, el concepto con su
 * clave 84111505 y los subtotales del complemento a partir de lo que va aquí.
 *
 * Solo se timbran **sueldos y salarios** (régimen 02) y **asimilados** (09).
 * Los honorarios no son nómina: los factura quien presta el servicio.
 */
class NominaLayout
{
    /** Periodicidad de la nómina → `c_PeriodicidadPago`. */
    public const PERIODICIDAD = ['semanal' => '02', 'catorcenal' => '03', 'quincenal' => '04', 'mensual' => '05'];

    /** Estados de la República → `c_Estado`, que es lo que pide `ClaveEntFed`; el rótulo lleva la clave. */
    public const ENTIDADES = [
        'AGU' => 'AGU · Aguascalientes', 'BCN' => 'BCN · Baja California', 'BCS' => 'BCS · Baja California Sur',
        'CAM' => 'CAM · Campeche', 'CHP' => 'CHP · Chiapas', 'CHH' => 'CHH · Chihuahua', 'CMX' => 'CMX · Ciudad de México',
        'COA' => 'COA · Coahuila', 'COL' => 'COL · Colima', 'DUR' => 'DUR · Durango', 'GUA' => 'GUA · Guanajuato',
        'GRO' => 'GRO · Guerrero', 'HID' => 'HID · Hidalgo', 'JAL' => 'JAL · Jalisco', 'MEX' => 'MEX · Estado de México',
        'MIC' => 'MIC · Michoacán', 'MOR' => 'MOR · Morelos', 'NAY' => 'NAY · Nayarit', 'NLE' => 'NLE · Nuevo León',
        'OAX' => 'OAX · Oaxaca', 'PUE' => 'PUE · Puebla', 'QUE' => 'QUE · Querétaro', 'ROO' => 'ROO · Quintana Roo',
        'SLP' => 'SLP · San Luis Potosí', 'SIN' => 'SIN · Sinaloa', 'SON' => 'SON · Sonora', 'TAB' => 'TAB · Tabasco',
        'TAM' => 'TAM · Tamaulipas', 'TLA' => 'TLA · Tlaxcala', 'VER' => 'VER · Veracruz', 'YUC' => 'YUC · Yucatán',
        'ZAC' => 'ZAC · Zacatecas',
    ];

    /**
     * @param  Collection<int, object>  $renglones  Percepciones y deducciones del
     *                                              empleado, ya con su clave SAT
     *                                              (`clave_sat`); sin lo patronal.
     */
    public function __construct(
        private object $nomina,
        private object $empleado,
        private object $emisor,
        private Collection $renglones,
        private string $folio,
        private string $fechaPago,
        private float $salarioIntegrado = 0,
        private float $subsidioCausado = 0,
    ) {}

    /** Datos que faltan para poder timbrar el recibo, con su etiqueta. */
    public static function faltantes(object $empleado, object $emisor): array
    {
        $requeridos = [
            'rfc' => 'RFC', 'curp' => 'CURP', 'codigo_postal' => __('C.P. fiscal'), 'entidad' => __('Estado donde labora'),
        ];

        if ($empleado->regimen === 'sueldos') {
            $requeridos += ['nss' => __('NSS'), 'ingreso' => __('Ingreso'), 'salario_diario' => __('Salario diario')];
        }

        $faltan = array_values(array_filter($requeridos, fn (string $campo) => blank($empleado->{$campo} ?? null), ARRAY_FILTER_USE_KEY));

        if ($empleado->regimen === 'sueldos') {
            foreach (['registro_patronal' => __('registro patronal'), 'riesgo_puesto' => __('clase de riesgo')] as $campo => $etiqueta) {
                if (blank($emisor->{$campo} ?? null)) {
                    $faltan[] = __('la compañía no tiene :dato', ['dato' => $etiqueta]);
                }
            }
        }

        return $faltan;
    }

    public function build(): string
    {
        $percepciones = $this->renglones->where('tipo', 'percepcion');
        $deducciones = $this->renglones->where('tipo', 'deduccion');

        if ($percepciones->isEmpty()) {
            throw new CfdiException(__('El recibo no tiene percepciones que timbrar.'));
        }

        // Los totales son la suma de los importes ya redondeados, igual que en
        // la factura: si no, el PAC rechaza por un centavo.
        $totalPercepciones = round($percepciones->sum(fn ($r) => round((float) $r->importe, 2)), 2);
        $totalDeducciones = round($deducciones->sum(fn ($r) => round((float) $r->importe, 2)), 2);
        $sueldos = $this->empleado->regimen === 'sueldos';

        $layout = "[ReciboNomina]\n"
            ."Version=4.0\n"
            ."serie=NOM\n"
            ."folio={$this->folio}\n"
            .'fecha='.now()->format('Y-m-d\TH:i:s')."\n"
            .'subTotal='.$this->money($totalPercepciones)."\n"
            .'descuento='.$this->money($totalDeducciones)."\n"
            .'total='.$this->money($totalPercepciones - $totalDeducciones)."\n"
            ."noCertificado=\n"
            ."LugarExpedicion={$this->emisor->postal_code}\n"
            ."\n[DatosAdicionales]\n"
            ."tipoDocumento=RECIBO DE NOMINA\n"
            ."observaciones=Nomina {$this->nomina->numero}\n"
            ."leyenda=Recibi pago del patron\n"
            ."\n[Emisor]\n"
            ."rfc={$this->emisor->rfc}\n"
            ."nombre={$this->emisor->business_name}\n"
            ."Regimen={$this->emisor->regimen_fiscal}\n";

        // Asimilados no llevan registro patronal: el SAT rechaza el recibo si viene.
        if ($sueldos) {
            $layout .= "RegistroPatronal={$this->emisor->registro_patronal}\n";
        }

        $layout .= "\n[Receptor]\n"
            .'rfc='.mb_strtoupper($this->empleado->rfc)."\n"
            .'nombre='.mb_strtoupper($this->empleado->nombre)."\n"
            ."DomicilioFiscalReceptor={$this->empleado->codigo_postal}\n"
            .'Curp='.mb_strtoupper($this->empleado->curp)."\n";

        if ($sueldos) {
            $layout .= "NumSeguridadSocial={$this->empleado->nss}\n"
                ."FechaInicioRelLaboral={$this->empleado->ingreso}\n"
                .'Antigüedad='.$this->antiguedad()."\n"
                ."TipoContrato=01\n"
                ."TipoRegimen=02\n";
        } else {
            $layout .= "TipoContrato=09\n"
                ."TipoRegimen=09\n";
        }

        if (filled($this->empleado->numero)) {
            $layout .= "NumEmpleado={$this->empleado->numero}\n";
        }

        foreach (['departamento' => 'Departamento', 'puesto' => 'Puesto'] as $campo => $clave) {
            if (filled($this->empleado->{$campo} ?? null)) {
                $layout .= "{$clave}={$this->empleado->{$campo}}\n";
            }
        }

        if ($sueldos) {
            $layout .= "RiesgoPuesto={$this->emisor->riesgo_puesto}\n";
        }

        $layout .= 'PeriodicidadPago='.(self::PERIODICIDAD[$this->nomina->periodicidad] ?? '99')."\n";

        // Con CLABE (18 dígitos) el SAT pide que NO venga el banco: lo saca de ella.
        if (preg_match('/^\d{18}$/', (string) $this->empleado->clabe) === 1) {
            $layout .= "CuentaBancaria={$this->empleado->clabe}\n";
        }

        if ($sueldos) {
            $layout .= 'SalarioBaseCotApor='.$this->money($this->salarioIntegrado)."\n"
                .'SalarioDiarioIntegrado='.$this->money($this->salarioIntegrado)."\n";
        }

        $layout .= "ClaveEntFed={$this->empleado->entidad}\n"
            ."\n[Concepto#1]\n"
            ."cantidad=1\n"
            ."unidad=ACT\n"
            ."descripcion=Pago de nómina\n"
            .'valorUnitario='.$this->money($totalPercepciones)."\n"
            .'importe='.$this->money($totalPercepciones)."\n"
            ."\n[ComplementoNomina]\n"
            ."Version=1.2\n"
            ."TipoNomina=O\n"
            ."FechaPago={$this->fechaPago}\n"
            ."FechaInicialPago={$this->nomina->desde}\n"
            ."FechaFinalPago={$this->nomina->hasta}\n"
            .'NumDiasPagados='.((int) Carbon::parse($this->nomina->desde)->diffInDays(Carbon::parse($this->nomina->hasta)) + 1)."\n"
            .'TotalPercepciones='.$this->money($totalPercepciones)."\n";

        if ($deducciones->isNotEmpty()) {
            $layout .= 'TotalDeducciones='.$this->money($totalDeducciones)."\n";
        }

        $subsidio = $sueldos && $this->subsidioCausado > 0;

        if ($subsidio) {
            $layout .= "TotalOtrosPagos=0.00\n";
        }

        foreach ($percepciones->values() as $i => $r) {
            $layout .= "\n[Percepcion#".($i + 1)."]\n"
                ."TipoPercepcion={$r->clave_sat}\n"
                .'Clave='.str_pad((string) $r->renglon_id, 3, '0', STR_PAD_LEFT)."\n"
                .'Concepto='.mb_strtoupper($r->concepto)."\n"
                // Todo se toma gravado, igual que al calcular el ISR.
                .'ImporteGravado='.$this->money($r->importe)."\n"
                ."ImporteExento=0.00\n";
        }

        foreach ($deducciones->values() as $i => $r) {
            $layout .= "\n[Deduccion#".($i + 1)."]\n"
                ."TipoDeduccion={$r->clave_sat}\n"
                .'Clave='.str_pad((string) $r->renglon_id, 3, '0', STR_PAD_LEFT)."\n"
                .'Concepto='.mb_strtoupper($r->concepto)."\n"
                .'Importe='.$this->money($r->importe)."\n";
        }

        // Desde 2024 el subsidio solo reduce el ISR (que ya viene neto), así que
        // se declara el causado con importe en cero, como el ejemplo del PAC.
        if ($subsidio) {
            $layout .= "\n[OtroPago#1]\n"
                ."TipoOtroPago=002\n"
                ."Clave=002\n"
                ."Concepto=SUBSIDIO AL EMPLEO\n"
                ."Importe=0.00\n"
                .'SubsidioAlEmpleo.SubsidioCausado='.$this->money($this->subsidioCausado)."\n";
        }

        return $layout;
    }

    /** Semanas cumplidas del ingreso al fin del periodo, en formato ISO (`P52W`). */
    private function antiguedad(): string
    {
        $dias = (int) Carbon::parse($this->empleado->ingreso)->diffInDays(Carbon::parse($this->nomina->hasta)) + 1;

        return 'P'.intdiv($dias, 7).'W';
    }

    private function money(float|string|null $valor): string
    {
        return number_format(round((float) $valor, 2), 2, '.', '');
    }
}
