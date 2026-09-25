<?php

namespace App\Support\Billing;

use App\Models\Core\Booking;
use App\Support\Expediente;
use App\Support\Rutas\Tarifario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La facturación del viaje cuando su origen → destino tiene ruta configurada.
 *
 * Manda sobre «Servicios y precios»: con ruta, la factura y el costo salen de
 * sus tarifas **vigentes en la fecha de carga**; sin ruta, todo sigue como
 * antes. Así no hay dos lugares con el precio del mismo flete.
 *
 * - Factura al cliente: la venta de la ruta, con su tarifa especial si la tiene.
 * - Costo: solo si el viaje va subcontratado (sin tractor propio), la tarifa de
 *   su transportista o, si no tiene, la del subcontratista más barato. Con
 *   unidad propia no se genera costo: el diésel y las casetas reales se
 *   capturan en «Gastos de viaje», y los de la ruta son un estimado.
 */
class RouteBilling
{
    /** La ruta activa del viaje, o null si no hay (o la instalación no es terrestre). */
    public static function rutaDe(Booking $booking): ?object
    {
        if (! Expediente::usa('terrestre') || $booking->loading_port === null || $booking->dicharge_port_id === null) {
            return null;
        }

        return DB::table('ruta as r')
            ->join('loading_ports as o', 'o.port_id', '=', 'r.origen_id')
            ->join('dicharge_port as d', 'd.dicharge_port_id', '=', 'r.destino_id')
            ->where('r.activo', 1)
            ->where('r.origen_id', $booking->loading_port)->where('r.destino_id', $booking->dicharge_port_id)
            ->first(['r.*', 'o.port_name as origen_nombre', 'd.name as destino_nombre']);
    }

    /** @return list<ServiceCandidate> */
    public static function forBlock(Booking $booking, BillingBlock $bloque, object $ruta): array
    {
        $fecha = $booking->loading_EDT ? Carbon::parse($booking->loading_EDT)->toDateString() : now()->toDateString();
        $vigentes = Tarifario::vigentes((int) $ruta->ruta_id, $fecha)->whereNotNull('charge_type_id');

        $tarifas = match ($bloque) {
            BillingBlock::Invoice => Tarifario::ventaPorConcepto($vigentes, (int) $booking->client),
            BillingBlock::Transport => $booking->unidad_id === null ? self::subcontrato($vigentes, $booking->transport_id) : [],
            // La naviera y el agente aduanal no existen en un viaje por carretera.
            default => [],
        };

        $moneda = (int) DB::table('account')->where('default', 1)->value('account_id');

        return array_map(fn (object $t) => new ServiceCandidate(
            block: $bloque,
            serviceId: 0,
            description: $t->concepto,
            price: (float) $t->precio,
            quantity: 1.0,
            chargeTypeId: (int) $t->charge_type_id,
            accountId: $moneda,
            priceType: null,
            containerTypeId: null,
            containerName: null,
            startDate: $t->vigente_desde,
            endDate: null,
            tarifaId: (int) $t->tarifa_id,
            providerId: $bloque === BillingBlock::Invoice ? null : (int) $t->provider_id,
        ), $tarifas);
    }

    /**
     * El transportista del viaje si tiene tarifa en la ruta; si no, el más barato.
     *
     * @return list<object>
     */
    private static function subcontrato($vigentes, ?int $transportista): array
    {
        $subcontratos = $vigentes->where('tipo', 'subcontrato')->whereNotNull('provider_id');
        $elegido = $subcontratos->firstWhere('provider_id', $transportista) ?? $subcontratos->sortBy('precio')->first();

        return $elegido === null ? [] : [$elegido];
    }
}
