<?php

namespace App\Models\Core;

/**
 * Tipo de cargo: define la tasa de IVA, la retención y si es no deducible.
 *
 * Es el catálogo que decide en qué cubeta cae cada cargo al agregarse:
 *  - `tax_rate = 0.16` y `non_deductible = 0` → cubeta VAT16
 *  - `tax_rate = 0`    y `non_deductible = 0` → cubeta VAT0
 *  - `tax_rate = 0`    y `non_deductible = 1` → cubeta no deducible
 */
class ChargeType extends CoreModel
{
    protected $table = 'charge_type';

    protected $primaryKey = 'charge_type_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'tax_rate' => 'float',
            'tax_retention' => 'float',
            'non_deductible' => 'boolean',
            'deleted' => 'boolean',
        ];
    }

    public function charges()
    {
        return $this->hasMany(Charge::class, 'type', 'charge_type_id');
    }

    /**
     * Tipos de cargo que tienen al menos un servicio contratado con esta
     * contraparte. Réplica de `ChargeType::getListFilter()` en Yii2: no se ofrece
     * un tipo de cargo suelto, sino los que de verdad se le pueden facturar.
     *
     * @return array<int, string>
     */
    public static function optionsFor(int $partyId, int $type): array
    {
        return static::query()
            ->join('service', 'service.charge_type_id', '=', 'charge_type.charge_type_id')
            ->where('service.'.Service::partyColumn($type), $partyId)
            ->where('service.type', $type)
            ->where('charge_type.deleted', 0)
            ->groupBy('charge_type.charge_type_id', 'charge_type.charge_type_name', 'charge_type.tax_name')
            ->orderBy('charge_type.charge_type_name')
            ->get(['charge_type.charge_type_id', 'charge_type.charge_type_name', 'charge_type.tax_name'])
            // La etiqueta se arma en PHP y no con CONCAT: el original usa MySQL,
            // pero las pruebas corren en SQLite y ahí esa función no existe.
            ->mapWithKeys(fn (self $tipo) => [
                (int) $tipo->charge_type_id => trim($tipo->charge_type_name.' - '.$tipo->tax_name, ' -'),
            ])
            ->all();
    }
}
