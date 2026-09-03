<?php

namespace App\Support\Billing;

/**
 * Una transacción por escribir, con sus conceptos.
 *
 * Existe para que la pantalla pueda enseñar el documento **antes** de que exista:
 * el original creaba la transacción, le colgaba los cargos y hasta entonces se
 * sabía qué había quedado.
 */
final class PlannedDocument
{
    /** @param  list<ServiceCandidate>  $lines */
    public function __construct(
        public readonly BillingBlock $block,
        public readonly int $accountId,
        public readonly ?int $customerId,
        public readonly ?int $vendorId,
        public readonly array $lines,
        /** Documentos idénticos por escribir. Solo el transportista pasa de 1. */
        public readonly int $copies = 1,
    ) {}

    /** Suma de los conceptos, sin impuestos: eso lo calcula el motor de consulta. */
    public function subtotal(): float
    {
        return array_sum(array_map(fn (ServiceCandidate $renglon) => $renglon->amount(), $this->lines));
    }

    /** Lo que se escribirá en total, contando las copias. */
    public function total(): float
    {
        return $this->subtotal() * $this->copies;
    }
}
