<?php

namespace App\Models\Core;

/**
 * Solicitud de pago: agrupa transacciones que se cobran/pagan juntas.
 * `type` 1 = cobro a cliente, 2 = pago a proveedor. `date` es la fecha de pago,
 * y de ahí sale el tipo de cambio con el que se valúa lo pagado.
 */
class PaymentRequest extends CoreModel
{
    protected $table = 'payment_request';

    protected $primaryKey = 'request_id';

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'total_to_pay' => 'float',
            'tc_value' => 'float',
            'date' => 'date',
            'paid' => 'boolean',
        ];
    }

    public function currency()
    {
        return $this->belongsTo(Account::class, 'currency_id', 'account_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class, 'provider_id', 'provider_id');
    }

    /** `payments` ya es una columna (longtext), así que la relación lleva otro nombre. */
    public function transactionPayments()
    {
        return $this->hasMany(PaymentByTransaction::class, 'request_id', 'request_id');
    }
}
