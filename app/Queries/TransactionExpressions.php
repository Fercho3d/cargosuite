<?php

namespace App\Queries;

/**
 * Las fórmulas de dinero del módulo, en un solo lugar.
 *
 * Son traducción literal de las expresiones de `TransactionSearch` en Yii2: mismos
 * `IFNULL`, mismo tratamiento del signo y misma condición de "esto suma o resta".
 * Se mantienen como SQL crudo a propósito — el objetivo es paridad exacta, y
 * cualquier reescritura "más limpia" arriesga cambiar un redondeo o un NULL.
 *
 * Vocabulario:
 *  - **cubetas**: subtotal 16 %, subtotal 0 %, no deducible, IVA y retención.
 *  - **signo**: las facturas al cliente suman; los costos de proveedor restan.
 *  - **TC documento**: tipo de cambio del día de la factura.
 *  - **TC pago**: tipo de cambio ponderado del día en que se cobró/pagó.
 */
class TransactionExpressions
{
    /** Subtotal de cargos no deducibles. */
    private string $nd = 'IFNULL(ca.nondec_subtotal, 0)';

    /** Subtotal de cargos con IVA 16 %. */
    private string $s16 = 'IFNULL(ca.vat16_subtotal, 0)';

    /** Subtotal de cargos con IVA 0 %. */
    private string $s0 = 'IFNULL(ca.vat0_subtotal, 0)';

    /** IVA trasladado de los cargos al 16 %. */
    private string $t16 = 'IFNULL(ca.vat16_tax, 0)';

    /** IVA retenido de los cargos al 16 %. */
    private string $ret = 'IFNULL(ca.vat16_ret, 0)';

    /** IVA de los cargos al 0 % (siempre 0; se conserva por paridad). */
    private string $t0 = 'IFNULL(ca.vat0_tax, 0)';

    public function __construct(
        private TransactionFilters $filters,
        private ?float $defaultRate,
    ) {}

    // ------------------------------------------------------ Piezas base

    /**
     * TC del documento: el capturado a mano gana; si no, el de la moneda base;
     * si no, el del día de la transacción.
     */
    public function exchangeValue(): string
    {
        $literal = $this->defaultRate === null ? 'NULL' : (string) $this->defaultRate;

        return "(CASE WHEN t.custom_tc IS NOT NULL THEN t.custom_tc
                      WHEN a.`default` = 1 THEN {$literal}
                      ELSE ex.exchange_value END)";
    }

    /**
     * "¿Esta transacción suma?" En modo pago/factura, todo lo que no sea nota de
     * crédito. En el modo normal, solo las facturas al cliente y las notas de
     * crédito de proveedor.
     *
     * Las comparaciones se dejan tal cual: con `invoice_type` NULL el resultado es
     * NULL y el CASE cae al ELSE, exactamente como en el original.
     */
    public function positiveCondition(): string
    {
        return ($this->filters->paymentMode || $this->filters->invoiceMode)
            ? '(t.tran_type <> 2 AND t.invoice_type <> 3)'
            : '((t.tran_type = 0 AND t.invoice_type <> 3) OR t.tran_type = 2)';
    }

    /** Multiplicador de conversión a pesos, o nada si se pidió sin conversión. */
    public function exchangeFactor(): string
    {
        $skip = ! empty($this->filters->transc_id_in) || $this->filters->noExchange;

        return $skip ? '' : ' * '.$this->exchangeValue();
    }

    /** Inversión de signo de los costos, o nada si se pidió sin invertir. */
    public function sign(): string
    {
        $keepPositive = $this->filters->noNegative
            && ! $this->filters->invoiceMode
            && ! $this->filters->paymentMode;

        return $keepPositive ? '' : ' * -1';
    }

    /** Total del documento: subtotales + IVA − retención. */
    public function documentTotal(): string
    {
        return "({$this->nd} + {$this->s16} + {$this->s0} + {$this->t16} - {$this->ret})";
    }

    /** Subtotal sin IVA: la base del profit (el IVA no es ganancia). */
    public function subtotal(): string
    {
        return "({$this->nd} + {$this->s16} + {$this->s0})";
    }

    /**
     * Envuelve una cubeta con su signo: suma tal cual cuando la transacción es
     * positiva, y con el signo invertido cuando es un costo.
     */
    private function signed(string $value, bool $withExchange = true): string
    {
        $x = $withExchange ? $this->exchangeFactor() : '';

        return "CASE WHEN {$this->positiveCondition()} THEN {$value}{$x} ELSE {$value}{$x}{$this->sign()} END";
    }

    // ------------------------------------------------------ Lista SELECT

    public function selectList(): string
    {
        $x = $this->exchangeFactor();
        $sign = $this->sign();
        $cond = $this->positiveCondition();
        $total = $this->documentTotal();
        $sub = $this->subtotal();
        $paidRate = 'IFNULL(p.paid_exchange_value, 0)';

        // Pagado, en su moneda: |documento| − |pagado|.
        $paidSum = 'ABS(SUM(IFNULL(p.tran_paid_amount, 0)))';

        return implode(",\n", [
            // --- Identificación y datos del documento ---
            'b.booking_id',
            'a.prefix AS currency',
            't.transc_id',
            't.tran_date',
            't.tran_number',
            't.tran_type',
            't.invoice',
            't.invoice_type',
            't.booking',
            't.account',
            't.account AS account_id',
            't.company_id',
            't.vendor',
            't.customer',
            't.request_id',
            't.payment_request',
            't.paid',
            't.cancelled',
            't.seal',
            't.pdf_attach',
            't.xml_attach',
            't.modified_by',
            'b.booking_number',
            'b.created_at AS booking_created_at',
            'co.name AS companyName',
            'vendor.fullName AS vendorName',
            'customer.fullName AS customerName',

            // --- Tipos de cambio ---
            $this->exchangeValue().' AS exchange_value',
            "{$paidRate} AS paid_exchange_value",

            // --- Cobrado/pagado, prorrateado por cubeta ---
            'IFNULL(p.tran_paid_amount, 0) AS tran_paid_amount',
            'IFNULL(p.sub_16_paid, 0) AS sub_16_paid',
            'IFNULL(p.sub_0_paid, 0) AS sub_0_paid',
            'IFNULL(p.tax_16_paid, 0) AS tax_16_paid',
            'IFNULL(p.tax_ret_paid, 0) AS tax_ret_paid',

            // --- Cubetas en su moneda ---
            "{$this->s0} AS subtotal_VAT0",
            "{$this->s16} AS subtotal_VAT16",

            // --- Cubetas convertidas y con signo ---
            'SUM('.$this->signed($this->nd).') AS non_dec',
            'SUM('.$this->signed($this->s16).') AS sub_16_mxn',
            'SUM('.$this->signed($this->s0).') AS sub_0_mxn',
            'SUM('.$this->signed($this->t16).') AS tax_16_mxn',
            'SUM('.$this->signed($this->ret).') AS tax_ret_mxn',
            'SUM('.$this->signed($this->t0).') AS tax_0_mxn',

            // --- Totales ---
            "SUM({$sub}) AS amount_original",
            'SUM('.$this->signed($total).') AS total_amount',
            'SUM('.$this->signed($sub).') AS amount_original_mxn',

            // Valuado al TC del día en que se pagó. Sin pago aún, el TC es 0 y la
            // columna queda en 0 (la pantalla la muestra vacía).
            'SUM('.$this->signed($total, withExchange: false).") * {$paidRate} AS total_amount_paid_tc",
            'SUM('.$this->signed($sub, withExchange: false).") * {$paidRate} AS amount_original_paid_mxn",

            // --- Total y saldo en la moneda del documento ---
            "CASE WHEN {$cond} THEN SUM({$total}) ELSE SUM({$total}){$sign} END AS total_natural_amount",
            "CASE WHEN {$cond} THEN SUM({$total}) - {$paidSum}
                  ELSE (SUM({$total}) - {$paidSum}){$sign} END AS left_to_pay",

            // --- Ingreso / egreso sin IVA, para el reporte de utilidad ---
            "SUM(CASE WHEN {$cond} THEN {$sub}{$x} ELSE 0 END) AS income",
            "SUM(CASE WHEN {$cond} THEN 0 ELSE {$sub}{$x} END) AS expense",
        ]);
    }

    // ------------------------------------------- Expresiones para HAVING

    /** |total del documento| — se usa en los filtros de estado de pago. */
    public function totalMagnitude(): string
    {
        return 'ABS(SUM('.$this->documentTotal().'))';
    }

    /** |total| − |pagado|: lo que falta por cobrar/pagar. */
    public function leftToPayMagnitude(): string
    {
        return $this->totalMagnitude().' - ABS(SUM(IFNULL(p.tran_paid_amount, 0)))';
    }
}
