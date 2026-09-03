<?php

namespace App\Support\Billing;

use App\Models\Core\Booking;
use App\Models\Core\Client;
use App\Models\Core\Service;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Empareja el booking con los servicios contratados que le corresponden.
 *
 * Porta `Booking::getInvoiceServices()`, `getBrockerServices()` y
 * `getServicesProvider()` de Yii2. La regla es siempre la misma: un servicio
 * marcado como **auto-incluible** entra si su ruta es la del booking —puerto de
 * carga, puerto de descarga, lugar de recolección, destino final— y, en los
 * bloques que dependen de la carga, si además hay contenedores de su tipo.
 *
 * Aquí solo se busca y se cuenta: no se escribe nada. Quien arma los documentos
 * es `App\Actions\Bookings\PlanBookingBilling` y quien los guarda,
 * `GenerateBookingBilling`.
 *
 * **Propone exactamente lo mismo que el original**, rarezas incluidas: eso es lo
 * que se está migrando. Las que se conservan a propósito llevan su nota y su
 * prueba de paridad contra la base real.
 */
class ServiceMatcher
{
    /** Columnas del servicio que necesita un renglón. */
    private const COLUMNS = [
        's.service_id', 's.description', 's.price', 's.account_id', 's.charge_type_id',
        's.price_type', 's.container_type_id', 's.start_date', 's.end_date', 's.active',
    ];

    /**
     * Los candidatos de los cuatro bloques, en el orden en que el original los
     * generaba.
     *
     * @return array<string, list<ServiceCandidate>>
     */
    public function candidates(Booking $booking): array
    {
        $candidatos = [];

        foreach (BillingBlock::cases() as $bloque) {
            $candidatos[$bloque->value] = $this->forBlock($booking, $bloque);
        }

        return $candidatos;
    }

    /** @return list<ServiceCandidate> */
    public function forBlock(Booking $booking, BillingBlock $bloque): array
    {
        return match ($bloque) {
            BillingBlock::Invoice => $this->invoiceCandidates($booking),
            BillingBlock::Carrier => $this->carrierCandidates($booking),
            BillingBlock::Transport => $this->transportCandidates($booking),
            BillingBlock::Broker => $this->brokerCandidates($booking),
        };
    }

    // ------------------------------------------------------ Factura al cliente

    /** @return list<ServiceCandidate> */
    private function invoiceCandidates(Booking $booking): array
    {
        if ($booking->client === null) {
            return [];
        }

        $contenedores = $this->totalContainers($booking);

        $renglones = array_merge(
            $this->clientRouteServices($booking),
            $this->brokerExtraServices($booking),
        );

        return array_map(
            fn (object $fila) => $this->candidate(
                BillingBlock::Invoice,
                $fila,
                $this->invoiceQuantity($fila, $contenedores),
            ),
            $renglones,
        );
    }

    /**
     * Servicios de venta cuya ruta es la del booking.
     *
     * El lugar de recolección solo entra en la comparación si el cliente lo pidió
     * (`client.match_pickup_place`): hay clientes que negocian un precio por
     * puerta y otros uno por puerto, y el original ya distinguía los dos casos.
     *
     * @return list<object>
     */
    private function clientRouteServices(Booking $booking): array
    {
        $consulta = $this->routeQuery($booking);

        $this->matchDimension($consulta, 'puerto_carga', 's.loading_port_id', $booking->loading_port);
        $this->matchDimension($consulta, 'puerto_descarga', 's.dicharge_port_id', $booking->dicharge_port_id);
        $this->matchDimension($consulta, 'destino_final', 's.final_destination_id', $booking->final_destination_id);
        $this->matchColumn($consulta, 's.client_id', $booking->client);

        if ($this->clientMatchesPickupPlace($booking)) {
            $this->matchDimension($consulta, 'lugar_recoleccion', 's.pickup_place_id', $booking->pick_up_place_id);
        }

        return $consulta->get()->all();
    }

    /**
     * Los servicios de despacho aduanal que se le cobran al cliente.
     *
     * No dependen de la ruta sino del agente aduanal: si el booking lleva uno, se
     * agregan a la factura todos los servicios del cliente cuyo precio es «por
     * contenedor de aduana» (3) o «por BL de aduana» (4). Es `getBrockerServices()`,
     * y va después de los de ruta para que el original arme los mismos documentos.
     *
     * ⚠️ Rareza que se conserva: es el único de los cinco caminos que **no filtra
     * por `active`**, así que un precio dado de baja sigue apareciendo en facturas
     * nuevas. Se deja igual que el original; la pantalla lo marca en rojo.
     *
     * @return list<object>
     */
    private function brokerExtraServices(Booking $booking): array
    {
        if ($booking->custom_brocker_id === null) {
            return [];
        }

        return DB::table('service as s')
            ->select(array_merge(self::COLUMNS, [DB::raw('null as container_name'), DB::raw('null as matched_quantity')]))
            ->where('s.client_id', $booking->client)
            ->where('s.auto_include', 1)
            ->whereIn('s.price_type', [Service::PRICE_BY_BROKER_CONTAINER, Service::PRICE_BY_BROKER_BL])
            ->orderBy('s.account_id')
            ->orderBy('s.service_id')
            ->get()
            ->all();
    }

    /** Cantidad del concepto en la factura, según cómo se pactó el precio. */
    private function invoiceQuantity(object $fila, float $contenedores): float
    {
        return match ((int) $fila->price_type) {
            Service::PRICE_BY_CONTAINER => (float) $fila->matched_quantity,
            Service::PRICE_BY_BROKER_CONTAINER => $contenedores,
            Service::PRICE_BY_BL, Service::PRICE_BY_BROKER_BL => 1.0,
            // Un servicio sin tipo de precio no sabe cuánto cobrar. El original
            // escribe el concepto con cantidad 0; aquí se conserva y la pantalla
            // lo señala.
            default => 0.0,
        };
    }

    // ------------------------------------------------------- Costo de la naviera

    /** @return list<ServiceCandidate> */
    private function carrierCandidates(Booking $booking): array
    {
        $naviera = $booking->carrier_id;

        if ($naviera === null) {
            return [];
        }

        $consulta = $this->routeQuery($booking);

        $this->matchDimension($consulta, 'puerto_carga', 's.loading_port_id', $booking->loading_port);
        $this->matchDimension($consulta, 'puerto_descarga', 's.dicharge_port_id', $booking->dicharge_port_id);
        $this->matchDimension($consulta, 'destino_final', 's.final_destination_id', $booking->final_destination_id);
        $consulta->where('s.provider_id', $naviera);

        // Si hay transportista, el acarreo lo cobra él: a la naviera se le piden
        // los servicios sin lugar de recolección. Si no lo hay, la naviera hace
        // también el acarreo y su precio sí depende de dónde se recoge.
        if ($this->usaDimension('lugar_recoleccion')) {
            $booking->transport_id === null
                ? $this->matchColumn($consulta, 's.pickup_place_id', $booking->pick_up_place_id)
                : $consulta->whereNull('s.pickup_place_id');
        }

        return array_map(
            fn (object $fila) => $this->candidate(BillingBlock::Carrier, $fila, $this->carrierQuantity($fila)),
            $consulta->get()->all(),
        );
    }

    private function carrierQuantity(object $fila): float
    {
        return (int) $fila->price_type === Service::PRICE_BY_BL
            ? 1.0
            : (float) $fila->matched_quantity;
    }

    // -------------------------------------------------- Costo del transportista

    /**
     * El acarreo terrestre se cobra por viaje: un costo por contenedor.
     *
     * ⚠️ Aquí vive la rareza más grande del original y **se replica tal cual**.
     * La consulta pide `SUM(containers.quantity)` **sin `GROUP BY`**, así que la
     * base colapsa en una sola fila todos los servicios que empataron: se queda
     * con los datos de uno y con una suma que ya viene multiplicada por cuántos
     * eran (servicios × contenedores). Con un solo precio por ruta —el caso
     * normal— sale lo correcto: un costo por contenedor.
     *
     * De qué fila salen los datos lo decide el plan de ejecución, que no es algo
     * que se pueda copiar. Se toma el primero del orden de la consulta
     * (divisa, luego id), que es con el que coincide en la base real; la prueba
     * de paridad lo compara booking por booking contra lo que devuelve el SQL
     * original, así que si alguna vez no coincidiera, se sabría.
     *
     * @return list<ServiceCandidate>
     */
    private function transportCandidates(Booking $booking): array
    {
        $transportista = $booking->transport_id;

        if ($transportista === null) {
            return [];
        }

        $consulta = DB::table('service as s')
            ->select(array_merge(self::COLUMNS, [DB::raw('null as container_name'), DB::raw('null as matched_quantity')]))
            ->where('s.auto_include', 1)
            ->where('s.active', 1)
            ->where('s.provider_id', $transportista)
            ->orderBy('s.account_id')
            ->orderBy('s.service_id');

        $this->matchDimension($consulta, 'puerto_carga', 's.loading_port_id', $booking->loading_port);
        $this->matchDimension($consulta, 'lugar_recoleccion', 's.pickup_place_id', $booking->pick_up_place_id);

        $empataron = $consulta->get()->all();

        if ($empataron === []) {
            return [];
        }

        $viajes = (int) $this->totalContainers($booking) * count($empataron);

        return [$this->candidate(BillingBlock::Transport, $empataron[0], 1.0, $viajes, count($empataron) - 1)];
    }

    // --------------------------------------------- Costo del agente aduanal

    /**
     * El despacho aduanal no depende de la ruta: se le piden al agente todos sus
     * servicios auto-incluibles. Es lo que hacía el original y tiene sentido,
     * porque sus honorarios son los mismos venga de donde venga la carga.
     *
     * @return list<ServiceCandidate>
     */
    private function brokerCandidates(Booking $booking): array
    {
        $agente = $booking->custom_brocker_id;

        if ($agente === null) {
            return [];
        }

        $filas = DB::table('service as s')
            ->select(array_merge(self::COLUMNS, [DB::raw('null as container_name'), DB::raw('null as matched_quantity')]))
            ->where('s.auto_include', 1)
            ->where('s.active', 1)
            ->where('s.provider_id', $agente)
            ->orderBy('s.account_id')
            ->orderBy('s.service_id')
            ->get()
            ->all();

        $contenedores = $this->totalContainers($booking);

        return array_map(
            fn (object $fila) => $this->candidate(
                BillingBlock::Broker,
                $fila,
                match ((int) $fila->price_type) {
                    Service::PRICE_BY_CONTAINER => $contenedores,
                    Service::PRICE_BY_BL => 1.0,
                    default => 0.0,
                },
            ),
            $filas,
        );
    }

    // ------------------------------------------------------------- Herramientas

    /**
     * Base de los bloques que dependen de la carga: el servicio tiene que ser de
     * un tipo de contenedor que el booking realmente lleve, y la cantidad sale de
     * sumar esos contenedores.
     *
     * El agrupado por `(tipo de contenedor, servicio)` es el del original: sin él,
     * la unión con `containers` repetiría el servicio una vez por contenedor.
     */
    private function routeQuery(Booking $booking): Builder
    {
        return DB::table('service as s')
            ->select(array_merge(self::COLUMNS, ['ct.container_name', DB::raw('SUM(cn.quantity) as matched_quantity')]))
            ->join('container_types as ct', 'ct.contType_id', '=', 's.container_type_id')
            ->join('containers as cn', fn ($union) => $union
                ->on('cn.container_type', '=', 'ct.contType_id')
                ->where('cn.booking', '=', $booking->booking_id))
            ->where('s.auto_include', 1)
            ->where('s.active', 1)
            ->groupBy('ct.contType_id', 's.service_id')
            ->orderBy('s.account_id')
            ->orderBy('s.service_id');
    }

    /**
     * Compara una columna de la ruta contra el booking.
     *
     * Va aparte porque el original se apoyaba en una costumbre de Yii2 que
     * Laravel no comparte: `['columna' => null]` allí significa `IS NULL`, y aquí
     * `where('columna', null)` no empata con nada. Sin esto, un booking sin
     * destino final dejaría de encontrar los servicios que tampoco lo tienen.
     */
    /**
     * Las dimensiones de ruta que esta instalación usa (`marca.emparejador_dimensiones`).
     *
     * Un negocio que no cobre por geografía las apaga y sus precios dejan de
     * estar atados a puertos que no tiene. El cliente y el proveedor NO pasan
     * por aquí a propósito: sin ellos, el precio de un proveedor se le aplicaría
     * a otro.
     */
    private function usaDimension(string $dimension): bool
    {
        $configuradas = array_filter(array_map(
            'trim',
            explode(',', (string) config('marca.emparejador_dimensiones')),
        ));

        return $configuradas === [] || in_array($dimension, $configuradas, true);
    }

    /** `matchColumn`, pero solo si la instalación empareja por esa dimensión. */
    private function matchDimension(Builder $consulta, string $dimension, string $columna, ?int $valor): void
    {
        if ($this->usaDimension($dimension)) {
            $this->matchColumn($consulta, $columna, $valor);
        }
    }

    private function matchColumn(Builder $consulta, string $columna, ?int $valor): void
    {
        $valor === null
            ? $consulta->whereNull($columna)
            : $consulta->where($columna, $valor);
    }

    private function clientMatchesPickupPlace(Booking $booking): bool
    {
        return (bool) (Client::find($booking->client)?->match_pickup_place ?? false);
    }

    /** Contenedores del booking, sumando las cantidades de cada renglón. */
    private function totalContainers(Booking $booking): float
    {
        return (float) DB::table('containers')->where('booking', $booking->booking_id)->sum('quantity');
    }

    private function candidate(BillingBlock $bloque, object $fila, float $cantidad, int $documentos = 1, int $descartados = 0): ServiceCandidate
    {
        return new ServiceCandidate(
            block: $bloque,
            serviceId: (int) $fila->service_id,
            description: (string) $fila->description,
            price: (float) $fila->price,
            quantity: $cantidad,
            chargeTypeId: (int) $fila->charge_type_id,
            accountId: (int) $fila->account_id,
            priceType: $fila->price_type === null ? null : (int) $fila->price_type,
            containerTypeId: $fila->container_type_id === null ? null : (int) $fila->container_type_id,
            containerName: $fila->container_name,
            startDate: $fila->start_date,
            endDate: $fila->end_date,
            active: (bool) $fila->active,
            documents: $documentos,
            discarded: $descartados,
        );
    }
}
