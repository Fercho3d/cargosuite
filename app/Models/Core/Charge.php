<?php

namespace App\Models\Core;

/**
 * Concepto (línea) de una transacción.
 *
 * `price * quantity` es el subtotal de la línea; el IVA y la retención salen de
 * multiplicar ese subtotal por las tasas del `charge_type`.
 */
class Charge extends CoreModel
{
    protected $table = 'charge';

    protected $primaryKey = 'charge_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit' => 'float',
            'price' => 'float',
            'price_confirmation' => 'float',
        ];
    }

    /** La columna se llama `transaction`, así que la relación no puede llamarse igual. */
    public function transactionModel()
    {
        return $this->belongsTo(Transaction::class, 'transaction', 'transc_id');
    }

    public function chargeType()
    {
        return $this->belongsTo(ChargeType::class, 'type', 'charge_type_id');
    }

    // Importes de la línea. Son los mismos que agrega el motor de consulta
    // (`price * quantity`, por las tasas del tipo de cargo), pero fila por fila
    // para poder enseñar el desglose. No son columnas de la tabla.

    public function getSubtotalAttribute(): float
    {
        return (float) $this->price * (float) $this->quantity;
    }

    public function getTaxAttribute(): float
    {
        return $this->subtotal * (float) ($this->chargeType?->tax_rate ?? 0);
    }

    public function getRetentionAttribute(): float
    {
        return $this->subtotal * (float) ($this->chargeType?->tax_retention ?? 0);
    }

    public function getTotalAttribute(): float
    {
        return $this->subtotal + $this->tax - $this->retention;
    }
}
