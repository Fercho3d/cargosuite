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

    /**
     * Firma de alta y modificación, como el `beforeSave` de Yii2. `created_at` es
     * DATE en la tabla; `modified_at`, DATETIME. Las correcciones de importe van
     * por consulta directa (sin eventos) y firman con `modificationStamp()`.
     */
    protected static function booted(): void
    {
        static::creating(function (self $renglon) {
            $renglon->forceFill(['created_at' => now()->toDateString(), 'created_by' => auth()->id()] + self::modificationStamp());
        });
    }

    /** @return array{modified_at: string, modified_by: int|string|null} */
    public static function modificationStamp(): array
    {
        return ['modified_at' => now()->toDateTimeString(), 'modified_by' => auth()->id()];
    }

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
