<?php

namespace App\Support\Billing;

/**
 * Todo lo que se va a escribir si el operador confirma: la factura del cliente y
 * los costos de sus proveedores, ya repartidos en documentos.
 */
final class BillingPlan
{
    /** @param  list<PlannedDocument>  $documents */
    public function __construct(public readonly array $documents = []) {}

    public function isEmpty(): bool
    {
        return $this->documents === [];
    }

    /** Cuántas transacciones se van a crear, contando las copias. */
    public function documentCount(): int
    {
        return array_sum(array_map(fn (PlannedDocument $documento) => $documento->copies, $this->documents));
    }

    /** Cuántos conceptos se van a escribir, contando las copias. */
    public function lineCount(): int
    {
        return array_sum(array_map(
            fn (PlannedDocument $documento) => count($documento->lines) * $documento->copies,
            $this->documents,
        ));
    }

    /** @return list<PlannedDocument> */
    public function forBlock(BillingBlock $bloque): array
    {
        return array_values(array_filter(
            $this->documents,
            fn (PlannedDocument $documento) => $documento->block === $bloque,
        ));
    }

    /** Total por divisa, para que la pantalla enseñe a cuánto asciende cada una. */
    public function totalsByAccount(BillingBlock $bloque): array
    {
        $totales = [];

        foreach ($this->forBlock($bloque) as $documento) {
            $totales[$documento->accountId] = ($totales[$documento->accountId] ?? 0) + $documento->total();
        }

        return $totales;
    }
}
