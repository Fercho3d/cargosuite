<?php

namespace App\Actions\Bookings;

use App\Actions\Transactions\SaveTransaction;
use App\Models\Core\Booking;
use App\Models\Core\Charge;
use App\Models\Core\Transaction;
use App\Models\User;
use App\Support\Billing\BillingPlan;
use App\Support\Billing\PlannedDocument;
use App\Support\Billing\ServiceCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escribe el plan: crea las transacciones y les cuelga sus conceptos.
 *
 * Es la parte que toca dinero, y por eso es tan corta: para cuando llega aquí ya
 * está decidido qué documentos salen, con qué divisa y con qué renglones. El
 * original mezclaba las dos cosas —consultaba, decidía y guardaba en el mismo
 * método— y no había manera de ver el resultado antes de tenerlo escrito.
 *
 * Las transacciones se crean con `SaveTransaction`, la misma puerta que usa el
 * alta manual: así el folio consecutivo, el tipo de cambio del día y las marcas
 * de auditoría salen de un solo lugar.
 *
 * ⚠️ En producción las tablas son MyISAM y **no honran la transacción de base de
 * datos**: si algo falla a la mitad, lo ya escrito se queda. Por eso el plan se
 * arma y se valida completo antes de escribir el primer renglón.
 */
class GenerateBookingBilling
{
    public function __construct(private SaveTransaction $guardar) {}

    /** @return list<Transaction> */
    public function handle(Booking $booking, BillingPlan $plan, User $usuario): array
    {
        if ($booking->locked) {
            throw ValidationException::withMessages([
                'plan' => 'El booking está cerrado: no admite documentos nuevos.',
            ]);
        }

        if ($plan->isEmpty()) {
            throw ValidationException::withMessages([
                'plan' => 'No hay nada que generar.',
            ]);
        }

        $fecha = now()->toDateString();

        return DB::transaction(function () use ($booking, $plan, $usuario, $fecha) {
            $creadas = [];

            foreach ($plan->documents as $documento) {
                for ($copia = 0; $copia < $documento->copies; $copia++) {
                    $creadas[] = $this->write($booking, $documento, $usuario, $fecha);
                }
            }

            return $creadas;
        });
    }

    private function write(Booking $booking, PlannedDocument $documento, User $usuario, string $fecha): Transaction
    {
        $esFactura = ! $documento->block->isBill();

        $transaccion = $this->guardar->handle(new Transaction, [
            'booking' => $booking->booking_id,
            'tran_type' => $esFactura ? Transaction::TYPE_INVOICE : Transaction::TYPE_BILL,
            'tran_date' => $fecha,
            'account' => $documento->accountId,
            'customer' => $documento->customerId,
            'vendor' => $documento->vendorId,
            'invoice_type' => $esFactura ? Transaction::INVOICE_TYPE_NORMAL : null,
        ], $usuario);

        foreach ($documento->lines as $renglon) {
            $this->writeCharge($transaccion, $renglon);
        }

        return $transaccion;
    }

    /**
     * El concepto guarda de qué servicio salió.
     *
     * El original lo hacía en los costos pero no en la factura: ahí tenía un
     * error de dedo (`$service->service_id = $service->service_id`, que se
     * asigna a sí mismo) y el concepto quedaba huérfano. Se corrige: la columna
     * ya existe, nadie decide nada con que esté vacía y saber de dónde salió un
     * precio es justo lo que se necesita cuando el cliente reclama.
     */
    private function writeCharge(Transaction $transaccion, ServiceCandidate $renglon): void
    {
        Charge::create([
            'transaction' => $transaccion->transc_id,
            'service_id' => $renglon->serviceId,
            'type' => $renglon->chargeTypeId,
            'description' => mb_substr($renglon->lineDescription(), 0, 100),
            'quantity' => $renglon->quantity,
            'price' => $renglon->price,
        ]);
    }
}
