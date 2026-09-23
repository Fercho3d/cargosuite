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
     * Datos fiscales que le faltan para poder timbrar, con su etiqueta. Son los
     * cuatro de `Company::fiscalRequired()` en Yii2: sin ellos el CFDI sale a
     * nombre equivocado o el PAC lo rechaza.
     *
     * @return string[]
     */
    public function missingFiscalFields(): array
    {
        $obligatorios = [
            'business_name' => __('Razón social'),
            'rfc' => 'RFC',
            'regimen_fiscal' => __('Régimen fiscal'),
            'postal_code' => __('C.P. del domicilio fiscal'),
        ];

        return array_values(array_filter($obligatorios, fn (string $campo) => blank($this->{$campo}), ARRAY_FILTER_USE_KEY));
    }

    /** Aviso listo para mostrar, o null si la compañía puede facturar. */
    public function fiscalWarning(): ?string
    {
        $faltan = $this->missingFiscalFields();

        if ($faltan === []) {
            return null;
        }

        return __('La compañía «:nombre» no tiene completos sus datos fiscales y no puede facturar. Falta capturar: :campos. Complétalos en el catálogo de compañías y vuelve a intentar.', [
            'nombre' => $this->name,
            'campos' => implode(', ', $faltan),
        ]);
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
