<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Booking;
use App\Support\Billing\BillingBlock;
use App\Support\Billing\ServiceMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Paridad del emparejamiento contra el sistema original, con datos reales.
 *
 * Para cada booking de la muestra se ejecuta el SQL que arma Yii2 en
 * `Booking::getInvoiceServices()` y `getServicesProvider()` —escrito aquí a mano,
 * no con el mismo constructor de consultas, para que la comparación valga— y se
 * confronta con lo que propone `ServiceMatcher`.
 *
 * Se comparan los cuatro bloques, rarezas incluidas: la promesa de esta pieza es
 * proponer exactamente lo que proponía el sistema viejo. El costo del
 * transportista se compara contra el SQL literal del original —esa suma sin
 * `GROUP BY` que colapsa todos los precios que empataron en un solo renglón—,
 * que es la única forma de saber si la fila que elige la base es la que se está
 * replicando.
 *
 * Corre contra la base local `frego` con datos de producción, así que es lenta:
 *   vendor/bin/phpunit --group parity
 */
#[Group('parity')]
class ServiceMatchingParityTest extends LegacyDatabaseTestCase
{
    /** Bookings recientes a comparar. */
    private const MUESTRA = 200;

    private ServiceMatcher $emparejador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emparejador = app(ServiceMatcher::class);
    }

    public function test_la_factura_al_cliente_empata_los_mismos_servicios(): void
    {
        $conPropuesta = 0;

        foreach ($this->bookings() as $booking) {
            $original = array_merge(
                $this->legacyClientRoute($booking),
                $this->legacyBrokerExtras($booking),
            );

            $this->assertSameServices($original, BillingBlock::Invoice, $booking);
            $conPropuesta += $original === [] ? 0 : 1;
        }

        // Sin esto la prueba pasaría comparando vacíos contra vacíos.
        $this->assertGreaterThan(10, $conPropuesta, 'La muestra no trajo facturas que comparar.');
    }

    public function test_el_costo_de_la_naviera_empata_los_mismos_servicios(): void
    {
        $conPropuesta = 0;

        foreach ($this->bookings() as $booking) {
            if ($booking->carrier_id === null) {
                continue;
            }

            $original = $this->legacyCarrier($booking);
            $this->assertSameServices($original, BillingBlock::Carrier, $booking);
            $conPropuesta += $original === [] ? 0 : 1;
        }

        $this->assertGreaterThan(10, $conPropuesta, 'La muestra no trajo costos de naviera que comparar.');
    }

    /**
     * En la base local ningún agente aduanal tiene servicios auto-incluibles, así
     * que aquí se compara sobre todo que los dos lados coincidan en no proponer
     * nada. Vale la pena igual: si mañana se cargan, la prueba ya está puesta.
     */
    public function test_el_costo_del_agente_aduanal_empata_los_mismos_servicios(): void
    {
        foreach ($this->bookings() as $booking) {
            if ($booking->custom_brocker_id === null) {
                continue;
            }

            $this->assertSameServices($this->legacyBroker($booking), BillingBlock::Broker, $booking);
        }
    }

    /**
     * El transportista, contra el renglón único que devuelve el original.
     *
     * Aquí se comprueba lo que no se puede deducir leyendo el código: **de qué
     * fila saca la base los datos** cuando colapsa varias, y que la cantidad
     * inflada (contenedores × precios que empataron) coincide.
     */
    public function test_el_transportista_replica_el_renglon_unico_del_original(): void
    {
        $comparados = 0;

        foreach ($this->bookings() as $booking) {
            if ($booking->transport_id === null) {
                continue;
            }

            $original = $this->legacyTransport($booking);
            $propuesta = $this->emparejador->forBlock($booking, BillingBlock::Transport);
            $mensaje = 'Booking '.$booking->booking_id;

            if ($original->service_id === null) {
                $this->assertSame([], $propuesta, $mensaje);

                continue;
            }

            $this->assertCount(1, $propuesta, $mensaje);
            $this->assertSame((int) $original->service_id, $propuesta[0]->serviceId, $mensaje);
            $this->assertSame((int) $original->quantity, $propuesta[0]->documents, $mensaje);
            $comparados++;
        }

        $this->assertGreaterThan(10, $comparados, 'La muestra no trajo acarreos que comparar.');
    }

    // ------------------------------------------------------------ Comparación

    /**
     * Mismos servicios, mismos tipos de contenedor y mismas cantidades.
     *
     * @param  list<object>  $original
     */
    private function assertSameServices(array $original, BillingBlock $bloque, Booking $booking): void
    {
        $contenedores = (float) $this->totalContainers($booking);

        $esperado = [];

        foreach ($original as $fila) {
            $esperado[$fila->service_id.':'.($fila->container_type_id ?? 0)] =
                $this->legacyQuantity($bloque, $fila, $contenedores);
        }

        $obtenido = [];

        foreach ($this->emparejador->forBlock($booking, $bloque) as $candidato) {
            $obtenido[$candidato->serviceId.':'.($candidato->containerTypeId ?? 0)] = $candidato->quantity;
        }

        ksort($esperado);
        ksort($obtenido);

        $this->assertEquals(
            $esperado,
            $obtenido,
            sprintf('Booking %d, bloque %s', $booking->booking_id, $bloque->value),
        );
    }

    /** La fórmula de cantidad del original, tal cual, por bloque. */
    private function legacyQuantity(BillingBlock $bloque, object $fila, float $contenedores): float
    {
        $tipo = (int) $fila->price_type;
        $empatados = (float) ($fila->quantity ?? 0);

        return match ($bloque) {
            BillingBlock::Invoice => match ($tipo) {
                1 => $empatados,
                3 => $contenedores,
                2, 4 => 1.0,
                default => 0.0,
            },
            BillingBlock::Carrier => $tipo === 2 ? 1.0 : $empatados,
            BillingBlock::Broker => match ($tipo) {
                1 => $contenedores,
                2 => 1.0,
                default => 0.0,
            },
            BillingBlock::Transport => 1.0,
        };
    }

    // --------------------------------------------------- El SQL del original

    /** @return list<object> */
    private function legacyClientRoute(Booking $booking): array
    {
        $ataduras = [$booking->booking_id];
        $donde = [
            $this->condition('service.loading_port_id', $booking->loading_port, $ataduras),
            $this->condition('service.dicharge_port_id', $booking->dicharge_port_id, $ataduras),
            $this->condition('service.final_destination_id', $booking->final_destination_id, $ataduras),
            $this->condition('service.client_id', $booking->client, $ataduras),
            'service.auto_include = 1',
            'active = 1',
        ];

        if ((int) (DB::table('client')->where('client_id', $booking->client)->value('match_pickup_place') ?? 0) === 1) {
            $donde[] = $this->condition('service.pickup_place_id', $booking->pick_up_place_id, $ataduras);
        }

        return $this->routeSelect($donde, $ataduras);
    }

    /** `getBrockerServices()` tal cual: sin filtro de ruta y sin filtro de baja. */
    private function legacyBrokerExtras(Booking $booking): array
    {
        if ($booking->custom_brocker_id === null) {
            return [];
        }

        return DB::select(
            'SELECT service_id, price_type, container_type_id, null AS quantity
             FROM service
             WHERE client_id = ? AND auto_include = 1 AND (price_type = 3 OR price_type = 4)
             ORDER BY account_id',
            [$booking->client],
        );
    }

    /** @return list<object> */
    private function legacyCarrier(Booking $booking): array
    {
        $ataduras = [$booking->booking_id];
        $donde = [
            $this->condition('service.loading_port_id', $booking->loading_port, $ataduras),
            $this->condition('service.dicharge_port_id', $booking->dicharge_port_id, $ataduras),
            $this->condition('service.final_destination_id', $booking->final_destination_id, $ataduras),
            'service.auto_include = 1',
            $this->condition('service.provider_id', $booking->carrier_id, $ataduras),
            'service.active = 1',
            $booking->transport_id === null
                ? $this->condition('pickup_place_id', $booking->pick_up_place_id, $ataduras)
                : 'pickup_place_id IS NULL',
        ];

        return $this->routeSelect($donde, $ataduras);
    }

    /** @return list<object> */
    private function legacyBroker(Booking $booking): array
    {
        return DB::select(
            'SELECT service_id, price_type, container_type_id, null AS quantity
             FROM service
             WHERE auto_include = 1 AND provider_id = ? AND active = 1
             ORDER BY account_id',
            [$booking->custom_brocker_id],
        );
    }

    /**
     * El SQL del transportista **tal cual lo arma Yii2**, con su `SUM` sin
     * `GROUP BY`. Devuelve siempre una fila: o la del precio que la base eligió
     * —con la cantidad ya multiplicada por cuántos empataron— o una de nulos.
     */
    private function legacyTransport(Booking $booking): object
    {
        $ataduras = [$booking->booking_id];
        $donde = [
            $this->condition('loading_port_id', $booking->loading_port, $ataduras),
            $this->condition('pickup_place_id', $booking->pick_up_place_id, $ataduras),
            'auto_include = 1',
            $this->condition('provider_id', $booking->transport_id, $ataduras),
            'active = 1',
        ];

        return DB::select(
            'SELECT service.service_id, SUM(containers.quantity) AS quantity
             FROM service
             INNER JOIN containers ON containers.booking = ?
             INNER JOIN container_types ON container_types.contType_id = containers.container_type
             WHERE '.implode(' AND ', $donde).'
             ORDER BY service.account_id',
            $ataduras,
        )[0];
    }

    /**
     * La consulta de ruta del original: el servicio tiene que ser de un tipo de
     * contenedor que el booking lleve, y la cantidad es la suma de esos
     * contenedores.
     *
     * @param  list<string>  $donde
     * @param  list<mixed>  $ataduras
     * @return list<object>
     */
    private function routeSelect(array $donde, array $ataduras): array
    {
        return DB::select(
            'SELECT service.service_id, service.price_type, service.container_type_id,
                    SUM(containers.quantity) AS quantity
             FROM service
             INNER JOIN container_types ON container_types.contType_id = service.container_type_id
             INNER JOIN containers ON containers.container_type = container_types.contType_id
                                  AND containers.booking = ?
             WHERE '.implode(' AND ', $donde).'
             GROUP BY container_types.contType_id, service.service_id
             ORDER BY service.account_id',
            $ataduras,
        );
    }

    /**
     * Traduce la costumbre de Yii2: `['columna' => null]` es `IS NULL`.
     *
     * @param  list<mixed>  $ataduras
     */
    private function condition(string $columna, ?int $valor, array &$ataduras): string
    {
        if ($valor === null) {
            return $columna.' IS NULL';
        }

        $ataduras[] = $valor;

        return $columna.' = ?';
    }

    // ------------------------------------------------------------- Utilidades

    /** @return Collection<int, Booking> */
    private function bookings()
    {
        return Booking::query()
            ->where('mode', Booking::MODE_BOOKING)
            ->whereNotNull('client')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('containers')->whereColumn('containers.booking', 'booking.booking_id'))
            ->orderByDesc('booking_id')
            ->limit(self::MUESTRA)
            ->get();
    }

    private function totalContainers(Booking $booking): int
    {
        return (int) DB::table('containers')->where('booking', $booking->booking_id)->sum('quantity');
    }
}
