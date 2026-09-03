<?php

namespace App\Actions\Portal;

use App\Models\Core\Bank;
use App\Models\Core\PaymentByTransaction;
use App\Models\Core\PaymentRequest;
use App\Models\Core\Transaction;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * El proveedor pide que le paguen los costos que ya comprobó.
 *
 * Porta `TransactionController::actionRequest` del portal. **No es lo mismo que
 * la solicitud de pago que arma la empresa por dentro**: aquí nadie está pagando
 * nada todavía. Se deja la solicitud abierta y el renglón que la liga con cada
 * documento **en cero**, y se marca la transacción como «pedida». Quien decide
 * cuánto y cuándo se paga sigue siendo la empresa, desde su pantalla.
 *
 * ⚠️ El importe NO se calcula con el SQL del portal sino con el motor del
 * sistema base (`TransactionQuery`). Es una diferencia deliberada: el portal
 * lleva su propia copia de las fórmulas —sin excluir no deducibles, con otro
 * redondeo— y no hay forma de saber cuánto se quedó atrás. El motor base es el
 * que está probado contra la base real.
 */
class RequestVendorPayment
{
    /**
     * @param  int[]  $transactionIds
     */
    public function handle(array $transactionIds, int $proveedorId): PaymentRequest
    {
        $documentos = $this->documentsOf($transactionIds, $proveedorId);

        $this->assertAny($documentos);
        $this->assertSameCurrency($documentos);
        $this->assertDocumentsUploaded($documentos);

        $solicitud = new PaymentRequest;
        $solicitud->forceFill([
            'number' => (string) ($numero = $this->nextNumber()),
            'temp_number' => $numero,
            'provider_id' => $proveedorId,
            'currency_id' => (int) $documentos->first()->account_id,
            'bank_id' => Bank::query()->where('default', 1)->value('bank_id'),
            'amount' => round($documentos->sum(fn ($d) => (float) $d->total_amount), 2),
            'type' => 2,
            'opened' => 1,
            'paid' => 0,
            // El original tampoco pone fecha: la solicitud todavía no se paga y
            // es la empresa quien fija con qué tipo de cambio se valúa.
        ])->save();

        foreach ($documentos as $documento) {
            $this->markRequested($documento, $solicitud->request_id);
        }

        return $solicitud;
    }

    /**
     * Los documentos pedidos, SIEMPRE acotados al proveedor de la sesión: los
     * identificadores llegan de la petición y no se les cree nada.
     *
     * @param  int[]  $ids
     * @return Collection<int, object>
     */
    private function documentsOf(array $ids, int $proveedorId): Collection
    {
        $filtros = TransactionFilters::make(['tran_in' => $ids]);
        $filtros->vendor = $proveedorId;
        $filtros->type = [1, 2];
        $filtros->noExchange = true;
        $filtros->paymentMode = true;

        return TransactionQuery::make($filtros)->get();
    }

    /** @param  Collection<int, object>  $documentos */
    private function assertAny(Collection $documentos): void
    {
        if ($documentos->isEmpty()) {
            throw ValidationException::withMessages([
                'seleccion' => __('Marca al menos un documento tuyo.'),
            ]);
        }
    }

    /** @param  Collection<int, object>  $documentos */
    private function assertSameCurrency(Collection $documentos): void
    {
        if ($documentos->pluck('account_id')->unique()->count() > 1) {
            throw ValidationException::withMessages([
                'seleccion' => __('No se pueden pedir juntos documentos de distinta divisa.'),
            ]);
        }
    }

    /**
     * Sin los DOS archivos no se puede pedir el pago. Es del original y tiene
     * sentido: la solicitud viaja a contabilidad con el comprobante.
     *
     * @param  Collection<int, object>  $documentos
     */
    private function assertDocumentsUploaded(Collection $documentos): void
    {
        $faltantes = $documentos
            ->filter(fn ($d) => blank($d->pdf_attach) || blank($d->xml_attach))
            ->map(fn ($d) => trim((string) $d->tran_number)
                ?: __('sin número, booking ').trim((string) $d->booking_number))
            ->all();

        if ($faltantes !== []) {
            throw ValidationException::withMessages([
                'seleccion' => __('Falta subir el PDF o el XML de: ').implode(', ', $faltantes).'.',
            ]);
        }
    }

    /**
     * El siguiente número de solicitud.
     *
     * El original lo busca dando un rodeo —la fila cuyo `number` coincide con el
     * mayor `temp_number`, y le suma uno a ESE número—, que revienta si ninguna
     * fila coincide. Aquí sale el mismo número por el camino corto.
     */
    private function nextNumber(): int
    {
        return (int) PaymentRequest::max('temp_number') + 1;
    }

    /**
     * Deja el documento «pedido»: el renglón de la solicitud va en CERO, porque
     * todavía no se paga nada, y la transacción queda marcada con la fecha.
     */
    private function markRequested(object $documento, int $requestId): void
    {
        PaymentByTransaction::create([
            'request_id' => $requestId,
            'transc_id' => $documento->transc_id,
            'amount' => 0,
            'paid' => 0,
        ]);

        Transaction::whereKey($documento->transc_id)->update([
            'payment_request' => 1,
            'request_id' => $requestId,
            // El original escribe aquí `date('Y-d-m H:i:s')` —año, DÍA, mes— y
            // MySQL guarda una fecha inválida en cuanto el día pasa de 12.
            // Es un defecto, no una convención: se anota y se escribe bien.
            'request_at' => now(),
        ]);
    }
}
