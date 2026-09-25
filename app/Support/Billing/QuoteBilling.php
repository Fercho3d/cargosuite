<?php

namespace App\Support\Billing;

use App\Models\Core\Booking;
use Illuminate\Support\Facades\DB;

/**
 * La factura al cliente de un viaje que salió de una cotización aceptada: sus
 * renglones, con el precio que se le cotizó —aunque la ruta haya cambiado de
 * tarifa después—. El costo sigue saliendo de la ruta.
 */
class QuoteBilling
{
    /** @return list<ServiceCandidate>|null null si el viaje no viene de una cotización. */
    public static function invoice(Booking $booking): ?array
    {
        if (empty($booking->cotizacion_id)) {
            return null;
        }

        $moneda = (int) DB::table('account')->where('default', 1)->value('account_id');

        return DB::table('cotizacion_renglon')->where('cotizacion_id', $booking->cotizacion_id)
            ->whereNotNull('charge_type_id')->orderBy('renglon_id')->get()
            ->map(fn (object $r) => new ServiceCandidate(
                block: BillingBlock::Invoice,
                serviceId: 0,
                description: $r->concepto,
                price: (float) $r->precio,
                quantity: (float) $r->cantidad,
                chargeTypeId: (int) $r->charge_type_id,
                accountId: $moneda,
                priceType: null,
                containerTypeId: null,
                containerName: null,
                startDate: null,
                endDate: null,
                renglonCotizacionId: (int) $r->renglon_id,
            ))->all();
    }
}
