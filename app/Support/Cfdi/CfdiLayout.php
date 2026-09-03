<?php

namespace App\Support\Cfdi;

use App\Models\Core\Charge;
use App\Models\Core\Transaction;
use Illuminate\Support\Collection;

/**
 * Arma el layout CFDI 4.0 que se le manda al PAC.
 *
 * No es XML: Facturación Moderna recibe un texto con secciones tipo INI y a
 * partir de él construye el comprobante. Es traducción de `models/CFDI.php` de
 * Yii2, sección por sección.
 *
 * **Por qué los totales se recalculan desde los conceptos.** El CFDI 4.0 exige
 * que los totales de impuestos y el total del comprobante sean la suma de los
 * importes **ya redondeados** de cada concepto, no el redondeo de un agregado
 * calculado aparte. Si no coinciden, el PAC rechaza con CFDI40211 (retenciones) o
 * CFDI40110 (total) por diferencias de un centavo. Por eso aquí se suma concepto
 * por concepto y no se usan los totales del motor de consulta.
 */
class CfdiLayout
{
    private float $sumaSubtotal = 0.0;

    private float $sumaBase16 = 0.0;

    private float $sumaTraslado16 = 0.0;

    private float $sumaBase0 = 0.0;

    private float $sumaRetencion = 0.0;

    private bool $hayIva16 = false;

    private bool $hayIva0 = false;

    private bool $hayRetencion = false;

    /**
     * @param  Collection<int, Charge>  $conceptos
     * @param  float|null  $tipoCambio  Del motor de consulta, no de la tabla.
     * @param  string|null  $numeroBooking  Ídem: se recibe, no se pega al modelo.
     */
    public function __construct(
        private object $transaccion,
        private ?object $emisor,
        private object $receptor,
        private Collection $conceptos,
        private string $rfcPorOmision,
        private string $nombrePorOmision,
        private string $lugarPorOmision,
        private ?float $tipoCambio = null,
        private ?string $numeroBooking = null,
    ) {}

    public function build(): string
    {
        if ($this->conceptos->isEmpty()) {
            throw new CfdiException('La transacción no tiene conceptos que facturar.');
        }

        $this->prepareTotals();

        return $this->header()
            .$this->emisorSection()
            .$this->receptorSection()
            .$this->conceptosSection()
            .$this->impuestosSection();
    }

    /** Formato de importe del layout: punto decimal y sin separador de miles. */
    private function money(float|string|null $valor, int $decimales = 4): string
    {
        return number_format(round((float) $valor, $decimales), $decimales, '.', '');
    }

    private function prepareTotals(): void
    {
        foreach ($this->conceptos as $concepto) {
            $importe = round($concepto->subtotal, 2);
            $this->sumaSubtotal += $importe;

            $tasa = (float) ($concepto->chargeType?->tax_rate ?? 0);
            $retencion = (float) ($concepto->chargeType?->tax_retention ?? 0);

            if ($tasa === 0.16) {
                $this->hayIva16 = true;
                $this->sumaBase16 += $importe;
                $this->sumaTraslado16 += round($concepto->tax, 2);
            }

            if ($tasa === 0.0 && $retencion === 0.0) {
                $this->hayIva0 = true;
                $this->sumaBase0 += $importe;
            }

            if ($retencion !== 0.0) {
                $this->hayRetencion = true;
                $this->sumaRetencion += round($concepto->retention, 2);
            }
        }
    }

    /** Total = SubTotal − Descuento + Traslados − Retenciones, con Descuento en cero. */
    private function total(): float
    {
        return $this->sumaSubtotal + $this->sumaTraslado16 - $this->sumaRetencion;
    }

    private function header(): string
    {
        $moneda = $this->transaccion->currency?->prefix ?? 'MXN';

        $layout = "[ComprobanteFiscalDigital]\n"
            ."Version=4.0\n"
            ."Serie=A\n"
            ."Folio={$this->transaccion->tran_number}\n"
            .'Fecha='.substr(date('c'), 0, 19)."\n"
            ."FormaPago={$this->receptor->pay_form}\n"
            ."NoCertificado=\n"
            ."CondicionesDePago=CONTADO\n"
            .'SubTotal='.$this->money($this->sumaSubtotal, 2)."\n"
            ."Descuento=00.00\n"
            .'Total='.$this->money($this->total(), 2)."\n"
            ."Moneda={$moneda}\n"
            // Tipo E (egreso) es la nota de crédito; el resto son ingresos.
            .'TipoDeComprobante='.((int) $this->transaccion->invoice_type === Transaction::INVOICE_TYPE_CREDIT ? 'E' : 'I')."\n"
            ."MetodoPago={$this->receptor->pay_method}\n"
            .'LugarExpedicion='.$this->lugarExpedicion()."\n"
            ."Exportacion=01\n";

        if ($moneda !== 'MXN') {
            $layout .= 'TipoCambio='.$this->tipoCambio."\n";
        }

        return $layout
            ."\n[DatosAdicionales]\n"
            ."tipoDocumento=Factura\n"
            .'observaciones=BookingNumber['.$this->numeroBooking."]\n"
            ."plantillaPDF=clasic\n"
            ."logotipo=lg_762e7575ee60be3fea9a2fb\n";
    }

    private function lugarExpedicion(): string
    {
        return filled($this->emisor?->postal_code) ? $this->emisor->postal_code : $this->lugarPorOmision;
    }

    /**
     * Datos del emisor según la compañía de la transacción (multiemisor).
     *
     * Si la transacción no trae compañía se usan los valores históricos, para no
     * romper facturas anteriores a que existiera el catálogo de compañías.
     */
    private function emisorSection(): string
    {
        $rfc = filled($this->emisor?->rfc) ? $this->emisor->rfc : $this->rfcPorOmision;
        $nombre = filled($this->emisor?->business_name)
            ? $this->emisor->business_name
            : $this->nombrePorOmision;
        $regimen = filled($this->emisor?->regimen_fiscal) ? $this->emisor->regimen_fiscal : '601';

        // El espacio después de `Rfc=` no es un descuido: así lo emite el sistema
        // original y el layout se compara carácter por carácter contra él.
        return "\n[Emisor]\n"
            ."Rfc= {$rfc}\n"
            ."Nombre={$nombre}\n"
            ."RegimenFiscal={$regimen}\n";
    }

    private function receptorSection(): string
    {
        return "\n[Receptor]\n"
            ."Rfc={$this->receptor->rfc}\n"
            ."UsoCFDI={$this->receptor->invoice_use}\n"
            ."Nombre={$this->receptor->fullName}\n"
            ."RegimenFiscalReceptor={$this->receptor->regimen_fiscal_id}\n"
            ."DomicilioFiscalReceptor={$this->receptor->postal_code}\n";
    }

    private function conceptosSection(): string
    {
        $layout = '';

        foreach ($this->conceptos->values() as $indice => $concepto) {
            $importe = $this->money($concepto->subtotal, 2);
            $tasa = number_format((float) ($concepto->chargeType?->tax_rate ?? 0), 6, '.', '');
            $retencion = number_format((float) ($concepto->chargeType?->tax_retention ?? 0), 6, '.', '');

            $layout .= "\n[Concepto#".($indice + 1)." ]\n"
                ."ClaveProdServ = {$concepto->chargeType?->product_code}\n"
                .'NoIdentificacion='.str_pad((string) $concepto->charge_id, 4, '0', STR_PAD_LEFT)."\n"
                .'Cantidad='.number_format((float) $concepto->quantity, 2, '.', '')."\n"
                ."ClaveUnidad=E48\n"
                ."Unidad=SERVICIO\n"
                ."ObjetoImp=02\n"
                ."Descripcion={$concepto->description}\n"
                .'ValorUnitario='.$this->money($concepto->price)."\n"
                ."Importe={$importe}\n"
                ."Descuento=0.00\n";

            // Un concepto al 16 % y uno exento se declaran igual; cambia la tasa.
            if ((float) ($concepto->chargeType?->tax_rate ?? 0) === 0.16
                || ((float) ($concepto->chargeType?->tax_rate ?? 0) === 0.0
                    && (float) ($concepto->chargeType?->tax_retention ?? 0) === 0.0)) {
                $layout .= "\nImpuestos.Traslados.Base=[{$importe}]\n"
                    ."Impuestos.Traslados.Impuesto=[002]\n"
                    ."Impuestos.Traslados.TipoFactor=[Tasa]\n"
                    ."Impuestos.Traslados.TasaOCuota=[{$tasa}]\n"
                    .'Impuestos.Traslados.Importe=['.$this->money($concepto->tax, 2)."]\n";
            }

            if ((float) ($concepto->chargeType?->tax_retention ?? 0) !== 0.0) {
                $layout .= "\nImpuestos.Retenciones.Base=[{$importe}]\n"
                    ."Impuestos.Retenciones.Impuesto=[002]\n"
                    ."Impuestos.Retenciones.TipoFactor=[Tasa]\n"
                    ."Impuestos.Retenciones.TasaOCuota=[{$retencion}]\n"
                    .'Impuestos.Retenciones.Importe=['.$this->money($concepto->retention, 2)."]\n";
            }
        }

        return $layout;
    }

    private function impuestosSection(): string
    {
        $layout = '';
        $bases = [];
        $importes = [];
        $cuotas = [];

        if ($this->hayIva16) {
            $bases[] = $this->money($this->sumaBase16, 2);
            $cuotas[] = '0.160000';
            $importes[] = $this->money($this->sumaTraslado16, 2);
        }

        if ($this->hayIva0) {
            $bases[] = $this->money($this->sumaBase0, 2);
            $cuotas[] = '0.000000';
            $importes[] = '0.00';
        }

        if ($bases !== []) {
            $impuestos = implode(',', array_fill(0, count($bases), '002'));
            $factores = implode(',', array_fill(0, count($bases), 'Tasa'));

            $layout .= "\n[Traslados]\n"
                .'Base=['.implode(',', $bases)."]\n"
                ."Impuesto=[{$impuestos}]\n"
                ."TipoFactor=[{$factores}]\n"
                .'TasaOCuota=['.implode(',', $cuotas)."]\n"
                .'TotalImpuestosTrasladados='.$this->money($this->sumaTraslado16, 2)."\n"
                .'Importe=['.implode(',', $importes)."]\n";
        }

        if ($this->hayRetencion) {
            $layout .= "\n[Retenciones]\n"
                .'TotalImpuestosRetenidos='.$this->money($this->sumaRetencion, 2)."\n"
                ."Impuesto=[002]\n"
                .'Importe=['.$this->money($this->sumaRetencion, 2)."]\n";
        }

        return $layout;
    }
}
