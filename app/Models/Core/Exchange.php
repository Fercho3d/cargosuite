<?php

namespace App\Models\Core;

/**
 * Tipo de cambio por (cuenta, fecha). Índice único `uq_date (date_exchange, account)`.
 */
class Exchange extends CoreModel
{
    protected $table = 'exchange';

    protected $primaryKey = 'exchange_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'exchange_value' => 'float',
            'date_exchange' => 'date',
        ];
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account', 'account_id');
    }
}
