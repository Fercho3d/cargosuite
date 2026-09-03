<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Booking;
use App\Support\Billing\BillingBlock;
use App\Support\Billing\ServiceCandidate;
use App\Support\Billing\ServiceMatcher;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Emparejamiento de la ruta del booking con los servicios contratados.
 *
 * Los números están hechos para poderse verificar a lápiz: el booking lleva dos
 * contenedores de 40 HC y tres de 20 DC, cinco en total.
 */
class ServiceMatchingTest extends TestCase
{
    /** Ruta del booking de las pruebas. */
    private const CARGA = 10;

    private const DESCARGA = 20;

    private const RECOLECCION = 30;

    private const DESTINO = 40;

    private const NAVIERA = 100;

    private const TRANSPORTE = 200;

    private const AGENTE = 300;

    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();

        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1],
            ['account_id' => 2, 'account_name' => 'Dólares', 'prefix' => 'USD', 'default' => 0],
        ]);

        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0.16, 'tax_retention' => 0],
        ]);

        DB::table('client')->insert([
            ['client_id' => 1, 'fullName' => 'Cliente por puerto', 'match_pickup_place' => 0],
            ['client_id' => 2, 'fullName' => 'Cliente por puerta', 'match_pickup_place' => 1],
        ]);

        DB::table('provider')->insert([
            ['provider_id' => self::NAVIERA, 'fullName' => 'Naviera', 'type_id' => 1],
            ['provider_id' => self::TRANSPORTE, 'fullName' => 'Transportista', 'type_id' => 2],
            ['provider_id' => self::AGENTE, 'fullName' => 'Agente aduanal', 'type_id' => 3],
        ]);

        DB::table('container_types')->insert([
            ['contType_id' => 1, 'container_name' => '40 HC'],
            ['contType_id' => 2, 'container_name' => '20 DC'],
        ]);
    }

    /** Un booking con la ruta completa y cinco contenedores. */
    private function booking(array $cambios = []): Booking
    {
        DB::table('booking')->insert([array_merge([
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0,
            'loading_port' => self::CARGA, 'dicharge_port_id' => self::DESCARGA,
            'pick_up_place_id' => self::RECOLECCION, 'final_destination_id' => self::DESTINO,
            'carrier_id' => self::NAVIERA, 'transport_id' => self::TRANSPORTE,
            'custom_brocker_id' => self::AGENTE,
        ], $cambios)]);

        DB::table('containers')->insert([
            ['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 2],
            ['container_ID' => 2, 'booking' => 1, 'container_type' => 2, 'quantity' => 3],
        ]);

        return Booking::findOrFail(1);
    }

    private function servicio(int $id, array $cambios = []): void
    {
        DB::table('service')->insert([array_merge([
            'service_id' => $id, 'description' => 'Servicio '.$id, 'price' => 100,
            'charge_type_id' => 1, 'account_id' => 1, 'active' => 1, 'auto_include' => 1,
            'price_type' => 1, 'type' => 1, 'client_id' => 1,
            'loading_port_id' => self::CARGA, 'dicharge_port_id' => self::DESCARGA,
            'final_destination_id' => self::DESTINO, 'pickup_place_id' => self::RECOLECCION,
            'container_type_id' => 1,
            'start_date' => null, 'end_date' => null,
        ], $cambios)]);
    }

    /** @return list<ServiceCandidate> */
    private function match(Booking $booking, BillingBlock $bloque): array
    {
        return app(ServiceMatcher::class)->forBlock($booking, $bloque);
    }

    // ------------------------------------------------------------- Factura

    public function test_la_cantidad_sale_de_como_se_pacto_el_precio(): void
    {
        $this->servicio(1, ['price_type' => 1]);                          // por contenedor de 40 HC
        $this->servicio(2, ['price_type' => 2]);                          // por BL
        $this->servicio(3, ['price_type' => 3]);                          // aduana, por contenedor
        $this->servicio(4, ['price_type' => 4]);                          // aduana, por BL
        $this->servicio(5, ['price_type' => null]);                       // sin definir

        $cantidades = collect($this->match($this->booking(), BillingBlock::Invoice))
            ->mapWithKeys(fn (ServiceCandidate $c) => [$c->serviceId => $c->quantity]);

        // El servicio es de 40 HC y el booking lleva dos.
        $this->assertSame(2.0, $cantidades[1]);
        $this->assertSame(1.0, $cantidades[2]);
        // Los de aduana se cobran sobre la carga completa: cinco contenedores.
        $this->assertSame(5.0, $cantidades[3]);
        $this->assertSame(1.0, $cantidades[4]);
        // Sin tipo de precio no hay cómo cobrar; el original escribe cantidad 0.
        $this->assertSame(0.0, $cantidades[5]);
    }

    public function test_el_concepto_lleva_pegado_el_tipo_de_contenedor(): void
    {
        $this->servicio(1, ['description' => 'Flete marítimo']);

        $renglon = $this->match($this->booking(), BillingBlock::Invoice)[0];

        $this->assertSame('Flete marítimo - 40 HC', $renglon->lineDescription());
    }

    public function test_un_servicio_de_otra_ruta_no_entra(): void
    {
        $this->servicio(1, ['dicharge_port_id' => 999]);

        $this->assertSame([], $this->match($this->booking(), BillingBlock::Invoice));
    }

    public function test_un_servicio_de_un_tipo_de_contenedor_que_no_viaja_no_entra(): void
    {
        // El booking lleva 40 HC y 20 DC; este servicio es de un tercer tipo.
        DB::table('container_types')->insert([['contType_id' => 3, 'container_name' => '40 RF']]);
        $this->servicio(1, ['container_type_id' => 3]);

        $this->assertSame([], $this->match($this->booking(), BillingBlock::Invoice));
    }

    public function test_un_servicio_dado_de_baja_no_entra(): void
    {
        $this->servicio(1, ['active' => 0]);
        $this->servicio(2, ['auto_include' => 0]);

        $this->assertSame([], $this->match($this->booking(), BillingBlock::Invoice));
    }

    /**
     * Regresión de la traducción: en Yii2, `['columna' => null]` significa
     * `IS NULL`; en Laravel, `where('columna', null)` no empata con nada. Un
     * booking sin destino final tiene que seguir encontrando los servicios que
     * tampoco lo tienen.
     */
    public function test_una_ruta_sin_destino_final_empata_con_servicios_sin_destino_final(): void
    {
        $this->servicio(1, ['final_destination_id' => null]);
        $this->servicio(2);

        $renglones = $this->match($this->booking(['final_destination_id' => null]), BillingBlock::Invoice);

        $this->assertCount(1, $renglones);
        $this->assertSame(1, $renglones[0]->serviceId);
    }

    public function test_el_lugar_de_recoleccion_solo_cuenta_si_el_cliente_lo_pidio(): void
    {
        $this->servicio(1, ['pickup_place_id' => 999]);

        // El cliente 1 negocia por puerto: el lugar de recolección no se compara.
        $this->assertCount(1, $this->match($this->booking(), BillingBlock::Invoice));

        DB::table('booking')->where('booking_id', 1)->update(['client' => 2]);
        DB::table('service')->where('service_id', 1)->update(['client_id' => 2]);

        // El cliente 2 negocia por puerta: ahí sí tiene que empatar.
        $this->assertSame([], $this->match(Booking::findOrFail(1), BillingBlock::Invoice));
    }

    public function test_los_servicios_de_aduana_del_cliente_se_agregan_a_la_factura(): void
    {
        // Sin ruta y de otro tipo de contenedor: entran por ser de aduana.
        $this->servicio(1, ['price_type' => 3, 'loading_port_id' => 999, 'container_type_id' => null]);

        $renglones = $this->match($this->booking(), BillingBlock::Invoice);

        $this->assertCount(1, $renglones);
        $this->assertSame(5.0, $renglones[0]->quantity);
        $this->assertNull($renglones[0]->containerName);
    }

    public function test_sin_agente_aduanal_no_hay_servicios_de_aduana(): void
    {
        $this->servicio(1, ['price_type' => 3, 'loading_port_id' => 999, 'container_type_id' => null]);

        $this->assertSame([], $this->match($this->booking(['custom_brocker_id' => null]), BillingBlock::Invoice));
    }

    /**
     * Rareza del original que se conserva: es el único camino que no filtra por
     * `active`, así que un precio de aduana dado de baja sigue entrando a la
     * factura. Si esto cambia algún día, que sea a propósito.
     */
    public function test_un_servicio_de_aduana_dado_de_baja_entra_igual(): void
    {
        $this->servicio(1, ['price_type' => 3, 'container_type_id' => null, 'active' => 0]);

        $this->assertCount(1, $this->match($this->booking(), BillingBlock::Invoice));
    }

    // ------------------------------------------------------------- Naviera

    public function test_con_transportista_la_naviera_no_cobra_la_recoleccion(): void
    {
        $this->servicio(1, ['client_id' => null, 'provider_id' => self::NAVIERA, 'type' => 2, 'pickup_place_id' => null]);
        $this->servicio(2, ['client_id' => null, 'provider_id' => self::NAVIERA, 'type' => 2]);

        $renglones = $this->match($this->booking(), BillingBlock::Carrier);

        $this->assertCount(1, $renglones);
        $this->assertSame(1, $renglones[0]->serviceId);
        $this->assertSame(2.0, $renglones[0]->quantity);
    }

    public function test_sin_transportista_la_naviera_cobra_desde_el_lugar_de_recoleccion(): void
    {
        $this->servicio(1, ['client_id' => null, 'provider_id' => self::NAVIERA, 'type' => 2, 'pickup_place_id' => null]);
        $this->servicio(2, ['client_id' => null, 'provider_id' => self::NAVIERA, 'type' => 2]);

        $renglones = $this->match($this->booking(['transport_id' => null]), BillingBlock::Carrier);

        $this->assertCount(1, $renglones);
        $this->assertSame(2, $renglones[0]->serviceId);
    }

    public function test_a_la_naviera_el_servicio_por_bl_se_le_paga_una_vez(): void
    {
        $this->servicio(1, [
            'client_id' => null, 'provider_id' => self::NAVIERA, 'type' => 2,
            'pickup_place_id' => null, 'price_type' => 2,
        ]);

        $this->assertSame(1.0, $this->match($this->booking(), BillingBlock::Carrier)[0]->quantity);
    }

    // -------------------------------------------------------- Transportista

    public function test_el_transportista_abre_un_costo_por_contenedor(): void
    {
        $this->servicio(1, [
            'client_id' => null, 'provider_id' => self::TRANSPORTE, 'type' => 2,
            'dicharge_port_id' => null, 'final_destination_id' => null, 'container_type_id' => null,
        ]);

        $renglon = $this->match($this->booking(), BillingBlock::Transport)[0];

        $this->assertSame(1.0, $renglon->quantity);
        $this->assertSame(5, $renglon->documents);
    }

    /**
     * Cuando varios precios empatan, el original devuelve un solo renglón: la
     * suma sin `GROUP BY` colapsa las filas y de paso multiplica la cantidad por
     * cuántos eran. Se replica igual —dos precios y cinco contenedores dan diez
     * costos del primer precio— y la pantalla avisa de los descartados.
     */
    public function test_con_varios_precios_el_transportista_replica_el_renglon_unico_del_original(): void
    {
        foreach ([1, 2] as $id) {
            $this->servicio($id, [
                'client_id' => null, 'provider_id' => self::TRANSPORTE, 'type' => 2,
                'price' => 1000 * $id, 'container_type_id' => null,
            ]);
        }

        $renglones = $this->match($this->booking(), BillingBlock::Transport);

        $this->assertCount(1, $renglones);
        $this->assertSame(1000.0, $renglones[0]->price);
        $this->assertSame(10, $renglones[0]->documents);
        $this->assertSame(1, $renglones[0]->discarded);
    }

    // ------------------------------------------------------- Agente aduanal

    public function test_el_agente_aduanal_no_depende_de_la_ruta(): void
    {
        $this->servicio(1, [
            'client_id' => null, 'provider_id' => self::AGENTE, 'type' => 2,
            'loading_port_id' => 999, 'price_type' => 1, 'container_type_id' => null,
        ]);
        $this->servicio(2, [
            'client_id' => null, 'provider_id' => self::AGENTE, 'type' => 2,
            'loading_port_id' => 999, 'price_type' => 2, 'container_type_id' => null,
        ]);

        $cantidades = collect($this->match($this->booking(), BillingBlock::Broker))
            ->mapWithKeys(fn (ServiceCandidate $c) => [$c->serviceId => $c->quantity]);

        $this->assertSame(5.0, $cantidades[1]);
        $this->assertSame(1.0, $cantidades[2]);
    }

    // ------------------------------------------------------------ Vigencia

    /** La vigencia no descarta nada, como en el original; solo se puede avisar. */
    public function test_la_vigencia_no_descarta_pero_si_se_sabe(): void
    {
        $this->servicio(1, ['start_date' => '2020-01-01', 'end_date' => '2020-12-31']);
        $this->servicio(2, ['start_date' => null, 'end_date' => null, 'container_type_id' => 2]);

        $renglones = collect($this->match($this->booking(), BillingBlock::Invoice))
            ->keyBy(fn (ServiceCandidate $c) => $c->serviceId);

        $this->assertCount(2, $renglones);
        $this->assertFalse($renglones[1]->isCurrentOn(now()));
        $this->assertTrue($renglones[2]->isCurrentOn(now()));
    }

    public function test_una_fecha_cero_del_esquema_viejo_es_sin_limite(): void
    {
        $renglon = new ServiceCandidate(
            block: BillingBlock::Invoice, serviceId: 1, description: 'x', price: 1, quantity: 1,
            chargeTypeId: 1, accountId: 1, priceType: 1, containerTypeId: null, containerName: null,
            startDate: '0000-00-00', endDate: null,
        );

        $this->assertTrue($renglon->isCurrentOn(now()));
    }
}
