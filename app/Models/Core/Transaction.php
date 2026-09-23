<?php

namespace App\Models\Core;

/**
 * Transacción: factura al cliente (`tran_type` 0), costo de proveedor (1) o
 * nota de crédito de proveedor (2).
 *
 * Nota sobre el esquema heredado: la llave primaria física es compuesta
 * (`transc_id`, `pdf_attach`), pero `transc_id` es AUTO_INCREMENT y por lo tanto
 * único por sí solo, así que Eloquent lo usa como llave.
 */
class Transaction extends CoreModel
{
    /** Factura emitida al cliente. */
    public const TYPE_INVOICE = 0;

    /** Costo facturado por un proveedor. */
    public const TYPE_BILL = 1;

    /** Nota de crédito de un proveedor (resta al costo). */
    public const TYPE_CREDIT_BILL = 2;

    /** Factura normal. */
    public const INVOICE_TYPE_NORMAL = 1;

    /** Factura histórica (migrada, no se timbra). */
    public const INVOICE_TYPE_HISTORY = 2;

    /** Nota de crédito al cliente (resta al ingreso). */
    public const INVOICE_TYPE_CREDIT = 3;

    protected $table = 'transaction';

    protected $primaryKey = 'transc_id';

    protected function casts(): array
    {
        return [
            'tran_date' => 'date',
            'paid_amount' => 'float',
            'custom_tc' => 'float',
            'request_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function charges()
    {
        return $this->hasMany(Charge::class, 'transaction', 'transc_id');
    }

    /*
     * OJO con los nombres: las columnas `booking`, `account`, `customer` y `vendor`
     * guardan el ID. Si la relación se llamara igual que la columna, Eloquent
     * devolvería el entero en vez del modelo (el atributo gana). Por eso las
     * relaciones llevan otro nombre — igual que hizo Yii2 con `getBookingModel()`.
     */

    public function bookingModel()
    {
        return $this->belongsTo(Booking::class, 'booking', 'booking_id');
    }

    /** Cuenta = divisa del documento (columna `account`). */
    public function currency()
    {
        return $this->belongsTo(Account::class, 'account', 'account_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    /** Cliente al que se factura (columna `customer`). */
    public function client()
    {
        return $this->belongsTo(Client::class, 'customer', 'client_id');
    }

    /** Proveedor que cobra (columna `vendor`). */
    public function provider()
    {
        return $this->belongsTo(Provider::class, 'vendor', 'provider_id');
    }

    public function request()
    {
        return $this->belongsTo(PaymentRequest::class, 'request_id', 'request_id');
    }

    public function payments()
    {
        return $this->hasMany(PaymentByTransaction::class, 'transc_id', 'transc_id');
    }

    /**
     * Números de las solicitudes de pago que pidieron estas filas, por
     * `request_id`, para pintar la columna «Solicitud» sin una consulta por renglón.
     *
     * @param  iterable<object>  $rows
     * @return array<int, string>
     */
    public static function requestNumbersFor(iterable $rows): array
    {
        $ids = collect($rows)->pluck('request_id')->filter()->unique()->values();

        return $ids->isEmpty() ? [] : PaymentRequest::whereIn('request_id', $ids)->pluck('number', 'request_id')->all();
    }

    /**
     * Etiqueta del tipo, igual que `Transaction::typeText()` en Yii2:
     * el prefijo lo pone `invoice_type` y el sustantivo `tran_type`.
     */
    public static function typeText(?int $invoiceType, ?int $tranType): string
    {
        $prefix = match ($invoiceType) {
            self::INVOICE_TYPE_HISTORY => 'History-',
            self::INVOICE_TYPE_CREDIT => 'Credit-',
            default => '',
        };

        $noun = match ($tranType) {
            self::TYPE_INVOICE => 'Invoice',
            self::TYPE_BILL => 'Bill',
            self::TYPE_CREDIT_BILL => 'Credit Bill',
            default => '',
        };

        return $prefix.$noun;
    }

    public function getTypeTextAttribute(): string
    {
        return static::typeText($this->invoice_type, $this->tran_type);
    }

    /**
     * El tipo, para enseñarlo: es `typeText` traducido. Sin esta columna una
     * nota de crédito de proveedor solo se distinguía de un costo por el signo.
     */
    public static function typeLabel(?int $invoiceType, ?int $tranType): string
    {
        return match (true) {
            $tranType === self::TYPE_BILL => __('Costo'),
            $tranType === self::TYPE_CREDIT_BILL => __('Nota de crédito prov.'),
            $invoiceType === self::INVOICE_TYPE_HISTORY => __('Histórica'),
            $invoiceType === self::INVOICE_TYPE_CREDIT => __('Nota de crédito cliente'),
            default => __('Factura'),
        };
    }

    /**
     * Por qué esta factura no se puede timbrar a nombre de su compañía, o null
     * si sí. Es `getEmisorError()` del original: sin compañía, o con una a la
     * que le faltan datos fiscales, el PAC timbraría a nombre equivocado.
     */
    public function emisorError(): ?string
    {
        if (blank($this->company_id)) {
            return __('Esta transacción no tiene compañía emisora, así que no se sabe con qué RFC timbrar. Asígnale una antes de timbrar.');
        }

        $compania = $this->company;

        if ($compania === null) {
            return __('La compañía emisora de esta transacción ya no existe. Asígnale una válida antes de timbrar.');
        }

        return $compania->fiscalWarning();
    }

    public function getFolioAttribute(): string
    {
        return str_pad((string) $this->invoice, 4, '0', STR_PAD_LEFT);
    }
}
