<?php

namespace App\Models\Core;

/**
 * Compañía interna emisora (multiemisor CFDI).
 */
class Company extends CoreModel
{
    protected $table = 'company';

    protected $primaryKey = 'company_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'company_id', 'company_id');
    }

    /**
     * Compañías para los selectores: `[company_id => nombre]`.
     * Sin caché — ver la nota en `Account::options()`.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::orderBy('name')->pluck('name', 'company_id')->all();
    }
}
