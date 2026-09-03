<?php

namespace App\Models\Core;

/**
 * Cuenta = divisa del documento (MXN, USD, EUR…).
 *
 * La cuenta marcada con `default = 1` es la moneda base: sus transacciones no se
 * convierten (su único tipo de cambio registrado vale 1.0000).
 */
class Account extends CoreModel
{
    protected $table = 'account';

    protected $primaryKey = 'account_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['default' => 'integer'];
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'account', 'account_id');
    }

    /**
     * Divisas para los selectores: `[account_id => prefijo]`, sin las cuentas que
     * no tienen prefijo.
     *
     * Sin caché a propósito: son 4 filas con llave primaria. Cachearlas ahorraba
     * microsegundos en una pantalla de ~175 ms y a cambio costó dos caídas en
     * producción (objetos serializados que no se podían releer, y archivos de caché
     * que quedaron con otro dueño). No todo lo que se puede cachear conviene.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::whereNotNull('prefix')->orderBy('account_id')->pluck('prefix', 'account_id')->all();
    }
}
