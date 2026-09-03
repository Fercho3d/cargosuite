<?php

namespace App\Support\Billing;

use App\Models\Core\Provider;

/**
 * Los cuatro documentos que un booking puede generar solo: la factura al cliente
 * y los costos de sus tres proveedores.
 *
 * En Yii2 no había un tipo para esto, sino cuatro llamadas seguidas en
 * `BookingController::actionGenerateInvoice()` y un entero suelto (1, 2, 3) que
 * viajaba por `generateBills()` decidiendo reglas por el camino.
 */
enum BillingBlock: string
{
    case Invoice = 'invoice';

    case Carrier = 'carrier';

    case Transport = 'transport';

    case Broker = 'broker';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Factura al cliente',
            self::Carrier => 'Costo de la naviera',
            self::Transport => 'Costo del transportista',
            self::Broker => 'Costo del agente aduanal',
        };
    }

    /** Cómo se le llama al tercero del bloque en una frase. */
    public function party(): string
    {
        return match ($this) {
            self::Invoice => 'cliente',
            self::Carrier => 'naviera',
            self::Transport => 'transportista',
            self::Broker => 'agente aduanal',
        };
    }

    /** Solo el primer bloque factura; los otros tres registran costos. */
    public function isBill(): bool
    {
        return $this !== self::Invoice;
    }

    /** Columna del booking donde vive el proveedor de este bloque. */
    public function providerColumn(): ?string
    {
        return match ($this) {
            self::Invoice => null,
            self::Carrier => 'carrier_id',
            self::Transport => 'transport_id',
            self::Broker => 'custom_brocker_id',
        };
    }

    /** Tipo del catálogo de proveedores al que corresponde el bloque. */
    public function providerType(): ?int
    {
        return match ($this) {
            self::Invoice => null,
            self::Carrier => Provider::TYPE_CARRIER,
            self::Transport => Provider::TYPE_TRANSPORT,
            self::Broker => Provider::TYPE_BROKER,
        };
    }
}
