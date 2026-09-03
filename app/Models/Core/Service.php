<?php

namespace App\Models\Core;

use Illuminate\Support\Collection;

/**
 * Servicio contratado con un cliente o con un proveedor.
 *
 * Es el catálogo del que salen los conceptos de una transacción: aporta la
 * descripción y, cuando trae precio, también el importe. `type` distingue el
 * servicio de venta (1, ligado a `client_id`) del de compra (2, `provider_id`).
 */
class Service extends CoreModel
{
    /** Servicio que se le vende a un cliente. */
    public const TYPE_CLIENT = 1;

    /** Servicio que compra la empresa a un proveedor. */
    public const TYPE_PROVIDER = 2;

    /*
     * Cómo se pactó el precio (`price_type`). Decide la cantidad del concepto
     * cuando el booking genera su factura y sus costos: los «por contenedor» se
     * multiplican por la carga y los «por BL» se cobran una sola vez.
     *
     * Los dos últimos solo existen en los servicios de venta y son los que se le
     * cobran al cliente por el despacho aduanal.
     */

    /** Por contenedor. */
    public const PRICE_BY_CONTAINER = 1;

    /** Por BL: uno por embarque. */
    public const PRICE_BY_BL = 2;

    /** Despacho aduanal, por contenedor. */
    public const PRICE_BY_BROKER_CONTAINER = 3;

    /** Despacho aduanal, por BL. */
    public const PRICE_BY_BROKER_BL = 4;

    protected $table = 'service';

    protected $primaryKey = 'service_id';

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'active' => 'boolean',
        ];
    }

    public function chargeType()
    {
        return $this->belongsTo(ChargeType::class, 'charge_type_id', 'charge_type_id');
    }

    /**
     * Servicios activos de un tipo de cargo para una contraparte concreta.
     * Réplica de `Service::getListFilter()` en Yii2.
     *
     * @return Collection<int, Service>
     */
    public static function optionsFor(int $chargeTypeId, int $partyId, int $type)
    {
        return static::query()
            ->where('charge_type_id', $chargeTypeId)
            ->where(static::partyColumn($type), $partyId)
            ->where('type', $type)
            ->where('active', 1)
            ->orderBy('description')
            ->get(['service_id', 'description', 'price']);
    }

    /** La columna que guarda la contraparte depende del sentido del servicio. */
    public static function partyColumn(int $type): string
    {
        return $type === self::TYPE_CLIENT ? 'client_id' : 'provider_id';
    }
}
