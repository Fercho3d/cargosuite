<?php

namespace App\Models\Core;

/**
 * Renglón que liga una solicitud de pago con una transacción y el monto aplicado.
 *
 * La tabla NO tiene llave primaria: su identidad es la pareja
 * (`request_id`, `transc_id`) del índice único `unique_transaction`.
 */
class PaymentByTransaction extends CoreModel
{
    protected $table = 'payments_by_transaction';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'paid' => 'boolean',
        ];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transc_id', 'transc_id');
    }

    public function request()
    {
        return $this->belongsTo(PaymentRequest::class, 'request_id', 'request_id');
    }
}
