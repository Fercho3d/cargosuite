<?php

namespace App\Support\Billing;

use Illuminate\Support\Carbon;

/**
 * Un servicio contratado que empata con la ruta del booking, ya con la cantidad
 * que le tocaría en el documento.
 *
 * Es lo que en Yii2 devolvían `getInvoiceServices()` y `getServicesProvider()`:
 * un `Service` con dos columnas de más pegadas por el `SELECT` (el nombre del
 * tipo de contenedor y la suma de contenedores que empataron). Aquí es un objeto
 * propio para que la pantalla pueda enseñar el porqué de cada renglón antes de
 * escribir nada.
 */
final class ServiceCandidate
{
    public function __construct(
        public readonly BillingBlock $block,
        public readonly int $serviceId,
        public readonly string $description,
        public readonly float $price,
        public readonly float $quantity,
        public readonly int $chargeTypeId,
        public readonly int $accountId,
        public readonly ?int $priceType,
        public readonly ?int $containerTypeId,
        public readonly ?string $containerName,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        /**
         * Si el servicio sigue de alta. Solo puede llegar en falso por los
         * servicios de aduana del cliente, el único camino que el original no
         * filtra por `active`.
         */
        public readonly bool $active = true,
        /**
         * Cuántos documentos abre este renglón. Solo lo usa el transportista: el
         * original le abre un costo por cada contenedor del booking —multiplicado
         * por los precios que empataron, ver `ServiceMatcher::transportCandidates()`—,
         * cada uno con un solo concepto de cantidad 1.
         */
        public readonly int $documents = 1,
        /**
         * Precios que empataron con la ruta y que el original descarta. Solo lo usa
         * el transportista, y está aquí para que la pantalla lo pueda decir.
         */
        public readonly int $discarded = 0,
    ) {}

    /**
     * Identidad del renglón dentro de la pantalla.
     *
     * Lleva el tipo de contenedor porque un mismo servicio puede empatar dos
     * veces: el original agrupa por `(tipo de contenedor, servicio)`, así que un
     * servicio sin tipo definido y otro con dos tipos distintos son renglones
     * diferentes.
     */
    public function key(): string
    {
        return $this->block->value.':'.$this->serviceId.':'.($this->containerTypeId ?? 0);
    }

    /** Texto que se escribe en el concepto, con el tipo de contenedor pegado. */
    public function lineDescription(): string
    {
        return $this->containerName === null || trim($this->containerName) === ''
            ? $this->description
            : $this->description.' - '.$this->containerName;
    }

    public function amount(): float
    {
        return $this->price * $this->quantity;
    }

    /**
     * Si el precio pactado sigue vigente en la fecha del documento.
     *
     * El original **no mira las fechas del servicio** al emparejar, y aquí tampoco:
     * un servicio caducado se propone igual, marcado como los demás. La vigencia se
     * calcula solo para poder avisarlo en pantalla, porque una ruta muy usada
     * acumula precios de años distintos y conviene que se note antes de confirmar.
     */
    public function isCurrentOn(Carbon $fecha): bool
    {
        $inicio = self::toDate($this->startDate);
        $fin = self::toDate($this->endDate);

        return ($inicio === null || $inicio->lessThanOrEqualTo($fecha))
            && ($fin === null || $fin->greaterThanOrEqualTo($fecha));
    }

    /** El esquema heredado guarda «sin fecha» de tres maneras distintas. */
    private static function toDate(?string $valor): ?Carbon
    {
        if ($valor === null || $valor === '' || str_starts_with($valor, '0000-00-00')) {
            return null;
        }

        return Carbon::parse($valor)->startOfDay();
    }
}
