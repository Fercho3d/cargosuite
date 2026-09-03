<?php

namespace App\Actions\Bookings;

use App\Models\Core\Booking;
use App\Support\Billing\BillingBlock;
use App\Support\Billing\BillingPlan;
use App\Support\Billing\PlannedDocument;
use App\Support\Billing\ServiceCandidate;

/**
 * Reparte los servicios que empataron en los documentos que se van a escribir.
 *
 * La regla de corte es la del original: los renglones vienen ordenados por
 * divisa y **se abre una transacción nueva cada vez que la divisa cambia**
 * respecto al renglón anterior. Una factura no puede mezclar pesos con dólares,
 * porque el documento entero se timbra en una sola moneda.
 *
 * Ojo con una consecuencia heredada: los servicios de despacho aduanal se pegan
 * al final de la lista con su propio orden, así que si la divisa vuelve a la de
 * un documento anterior se abre otro documento en vez de sumarse al primero. Se
 * conserva para no cambiar el número de facturas que el cliente recibe.
 */
class PlanBookingBilling
{
    /**
     * @param  list<ServiceCandidate>  $candidatos  Los renglones elegidos.
     */
    public function handle(Booking $booking, array $candidatos): BillingPlan
    {
        $documentos = [];

        foreach (BillingBlock::cases() as $bloque) {
            $delBloque = array_values(array_filter(
                $candidatos,
                fn (ServiceCandidate $candidato) => $candidato->block === $bloque,
            ));

            $documentos = [...$documentos, ...$this->group($booking, $bloque, $delBloque)];
        }

        return new BillingPlan($documentos);
    }

    /**
     * @param  list<ServiceCandidate>  $candidatos
     * @return list<PlannedDocument>
     */
    private function group(Booking $booking, BillingBlock $bloque, array $candidatos): array
    {
        if ($candidatos === []) {
            return [];
        }

        // El acarreo terrestre se cobra por viaje: cada servicio abre tantos
        // costos como contenedores lleve el booking, cada uno con su concepto.
        if ($bloque === BillingBlock::Transport) {
            return array_values(array_filter(array_map(
                fn (ServiceCandidate $candidato) => $candidato->documents > 0
                    ? $this->document($booking, $bloque, [$candidato], $candidato->documents)
                    : null,
                $candidatos,
            )));
        }

        $documentos = [];
        $actual = [];

        foreach ($candidatos as $candidato) {
            if ($actual !== [] && end($actual)->accountId !== $candidato->accountId) {
                $documentos[] = $this->document($booking, $bloque, $actual, 1);
                $actual = [];
            }

            $actual[] = $candidato;
        }

        $documentos[] = $this->document($booking, $bloque, $actual, 1);

        return $documentos;
    }

    /** @param  list<ServiceCandidate>  $lineas */
    private function document(Booking $booking, BillingBlock $bloque, array $lineas, int $copias): PlannedDocument
    {
        $columna = $bloque->providerColumn();

        return new PlannedDocument(
            block: $bloque,
            accountId: $lineas[0]->accountId,
            customerId: $bloque->isBill() ? null : (int) $booking->client,
            vendorId: $columna === null ? null : (int) $booking->{$columna},
            lines: array_values($lineas),
            copies: $copias,
        );
    }
}
