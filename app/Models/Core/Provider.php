<?php

namespace App\Models\Core;

/**
 * Proveedor. En el módulo de transacciones aparece como `vendor`.
 */
class Provider extends CoreModel
{
    protected $table = 'provider';

    protected $primaryKey = 'provider_id';

    protected $hidden = ['password', 'auth_key', 'password_reset_token', 'verification_code'];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'vendor', 'provider_id');
    }

    /** Naviera. */
    public const TYPE_CARRIER = 1;

    /** Transportista terrestre. */
    public const TYPE_TRANSPORT = 2;

    /** Agente aduanal. */
    public const TYPE_BROKER = 3;

    /**
     * Proveedores para los selectores: `[provider_id => fullName]`.
     * Réplica de `Provider::getList()` en Yii2.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::orderBy('fullName')->pluck('fullName', 'provider_id')->all();
    }

    /**
     * Proveedores de un tipo concreto, como `Provider::getList($type)` en Yii2:
     * el booking pide por separado la naviera, el transportista y el agente
     * aduanal, y son todos proveedores.
     *
     * @return array<int, string>
     */
    public static function optionsByType(int $type): array
    {
        return static::where('type_id', $type)->orderBy('fullName')->pluck('fullName', 'provider_id')->all();
    }
}
