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

    public function getFolioAttribute(): string
    {
        return str_pad((string) $this->invoice, 4, '0', STR_PAD_LEFT);
    }
}
