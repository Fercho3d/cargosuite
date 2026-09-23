<?php

namespace App\Support\Cfdi;

/**
 * Lo que el SAT contesta sobre un comprobante.
 *
 * Con `estado` en null la consulta no se pudo hacer (falta el XML, el servicio
 * no contestó) y `motivo` dice por qué: **eso no es un comprobante cancelado**,
 * y por eso los dos casos se distinguen.
 */
readonly class SatStatusResult
{
    public function __construct(
        /** Vigente | Cancelado | No Encontrado, o null si no se pudo consultar. */
        public ?string $estado,
        /** Cancelable sin aceptación | Cancelable con aceptación | No cancelable. */
        public ?string $esCancelable = null,
        /** Vacío | En proceso | Cancelado sin aceptación | Cancelado con aceptación | Plazo vencido | Solicitud rechazada. */
        public ?string $estatusCancelacion = null,
        public ?string $codigoEstatus = null,
        /** Por qué no se pudo consultar, cuando `estado` viene en null. */
        public ?string $motivo = null,
    ) {}

    public function seConsulto(): bool
    {
        return $this->estado !== null;
    }

    public function estaCancelado(): bool
    {
        return $this->estado === 'Cancelado';
    }
}
