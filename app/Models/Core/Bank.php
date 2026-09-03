<?php

namespace App\Models\Core;

/** Cuenta bancaria desde la que se paga o a la que se cobra. */
class Bank extends CoreModel
{
    protected $table = 'bank';

    protected $primaryKey = 'bank_id';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'default' => 'boolean',
        ];
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return static::where('active', 1)->orderBy('bank_name')->pluck('bank_name', 'bank_id')->all();
    }
}
