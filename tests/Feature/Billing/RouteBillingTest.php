<?php

namespace Tests\Feature\Billing;

use App\Livewire\Operations\BillingGenerator;
use App\Models\Core\Booking;
use App\Models\User;
use App\Support\Billing\BillingBlock;
use App\Support\Billing\ServiceMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Con ruta configurada, la factura y el costo del viaje salen de la ruta y no
 * de «Servicios y precios».
 */
class RouteBillingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        Http::preventStrayRequests();
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [['datos' => []]]]])]);
        config(['marca.modalidades' => 'terrestre']);

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1]]);
        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0.16, 'tax_retention' => 0.04],
            ['charge_type_id' => 2, 'charge_type_name' => 'Maniobras', 'tax_rate' => 0.16, 'tax_retention' => 0],
        ]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cementos'], ['client_id' => 2, 'fullName' => 'Aceros']]);
        DB::table('provider')->insert([
            ['provider_id' => 7, 'fullName' => 'Fletes Caros', 'type_id' => 2],
            ['provider_id' => 8, 'fullName' => 'Fletes Baratos', 'type_id' => 2],
        ]);
        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'Monterrey', 'deleted' => 0]);
        DB::table('dicharge_port')->insert(['dicharge_port_id' => 1, 'name' => 'Ciudad de México', 'deleted' => 0]);
        DB::table('ruta')->insert(['ruta_id' => 1, 'origen_id' => 1, 'destino_id' => 1, 'km' => 900, 'rendimiento' => 2.2, 'activo' => 1]);

        $tarifa = fn (string $tipo, string $concepto, float $precio, string $desde, ?int $cliente = null, ?int $proveedor = null, int $cargo = 1) => [
            'ruta_id' => 1, 'tipo' => $tipo, 'concepto' => $concepto, 'client_id' => $cliente, 'provider_id' => $proveedor,
            'precio' => $precio, 'vigente_desde' => $desde, 'charge_type_id' => $cargo,
        ];
        DB::table('tarifa_ruta')->insert([
            $tarifa('venta', 'Flete', 24000, '2026-01-01'),
            $tarifa('venta', 'Flete', 25200, '2026-08-01'),
            $tarifa('venta', 'Maniobras', 2500, '2026-01-01', cargo: 2),
            $tarifa('venta', 'Flete', 23000, '2026-01-01', cliente: 2),
            $tarifa('subcontrato', 'Flete subcontratado', 22000, '2026-01-01', proveedor: 7),
            $tarifa('subcontrato', 'Flete subcontratado', 21000, '2026-01-01', proveedor: 8),
        ]);

        // Un servicio de la misma ruta: con ruta configurada ya no debe proponerse.
        DB::table('service')->insert([
            'service_id' => 50, 'description' => 'Flete viejo', 'price' => 999, 'charge_type_id' => 1, 'account_id' => 1,
            'active' => 1, 'auto_include' => 1, 'price_type' => 2, 'type' => 1, 'client_id' => 1,
            'loading_port_id' => 1, 'dicharge_port_id' => 1,
        ]);
    }

    private function viaje(array $cambios = []): Booking
    {
        DB::table('booking')->insert([array_merge([
            'booking_id' => 1, 'booking_number' => 'VJ-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0, 'locked' => 0,
            'loading_port' => 1, 'dicharge_port_id' => 1, 'loading_EDT' => '2026-09-10', 'unidad_id' => 3,
        ], $cambios)]);

        return Booking::findOrFail(1);
    }

    /** @return array<string, float> concepto => precio */
    private function propuesta(Booking $viaje, BillingBlock $bloque): array
    {
        return collect(app(ServiceMatcher::class)->forBlock($viaje, $bloque))
            ->mapWithKeys(fn ($c) => [$c->description => $c->price])->all();
    }

    /** La tarifa vigente en la fecha de carga, no la de hoy ni la vieja; y sin el servicio. */
    public function test_la_factura_sale_de_la_ruta_con_la_tarifa_de_la_fecha_de_carga(): void
    {
        $this->assertSame(['Flete' => 25200.0, 'Maniobras' => 2500.0], $this->propuesta($this->viaje(), BillingBlock::Invoice));
    }

    public function test_un_viaje_viejo_se_factura_con_la_tarifa_de_entonces(): void
    {
        $this->assertSame(['Flete' => 24000.0, 'Maniobras' => 2500.0], $this->propuesta($this->viaje(['loading_EDT' => '2026-07-15']), BillingBlock::Invoice));
    }

    public function test_el_cliente_con_tarifa_especial_paga_la_suya(): void
    {
        $this->assertSame(['Flete' => 23000.0, 'Maniobras' => 2500.0], $this->propuesta($this->viaje(['client' => 2]), BillingBlock::Invoice));
    }

    /** Con unidad propia el costo real va en Gastos de viaje: no se genera costo. */
    public function test_con_unidad_propia_no_se_genera_costo(): void
    {
        $this->assertSame([], $this->propuesta($this->viaje(), BillingBlock::Transport));
    }

    /** Subcontratado: la tarifa de su transportista, o si no tiene, la más barata. */
    public function test_subcontratado_se_le_paga_a_su_transportista_o_al_mas_barato(): void
    {
        $conTransportista = collect(app(ServiceMatcher::class)->forBlock($this->viaje(['unidad_id' => null, 'transport_id' => 7]), BillingBlock::Transport));
        DB::table('booking')->where('booking_id', 1)->update(['transport_id' => null]);
        $sinTransportista = collect(app(ServiceMatcher::class)->forBlock(Booking::findOrFail(1), BillingBlock::Transport));

        $this->assertSame([[7, 22000.0], [8, 21000.0]], [
            [$conTransportista->first()->providerId, $conTransportista->first()->price],
            [$sinTransportista->first()->providerId, $sinTransportista->first()->price],
        ]);
    }

    /** Sin ruta para ese origen → destino, todo sigue como antes: servicios. */
    public function test_sin_ruta_sigue_con_los_servicios(): void
    {
        DB::table('ruta')->update(['activo' => 0]);
        DB::table('containers')->insert(['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 1]);
        DB::table('container_types')->insert(['contType_id' => 1, 'container_name' => 'Caja']);
        DB::table('service')->where('service_id', 50)->update(['container_type_id' => 1]);

        $this->assertSame(['Flete viejo - Caja' => 999.0], collect(app(ServiceMatcher::class)->forBlock($this->viaje(), BillingBlock::Invoice))
            ->mapWithKeys(fn ($c) => [$c->lineDescription() => $c->price])->all());
    }

    /** Confirmar escribe la factura al cliente y el costo al subcontratista, con su tipo de cargo. */
    public function test_generar_escribe_la_factura_y_el_costo_del_subcontratista(): void
    {
        $this->viaje(['unidad_id' => null]);
        $this->actingAs(User::forceCreate(['username' => 'jefa', 'password' => 'secreto-de-prueba', 'role' => User::ROLE_ADMIN, 'status' => 1]));

        Livewire::test(BillingGenerator::class, ['booking' => 1])->call('generate')->assertHasNoErrors();

        $this->assertSame([
            ['tipo' => 0, 'cliente' => 1, 'proveedor' => null, 'conceptos' => ['Flete|1|25200', 'Maniobras|2|2500']],
            ['tipo' => 1, 'cliente' => null, 'proveedor' => 8, 'conceptos' => ['Flete subcontratado|1|21000']],
        ], DB::table('transaction')->orderBy('transc_id')->get()->map(fn ($t) => [
            'tipo' => (int) $t->tran_type,
            'cliente' => $t->customer === null ? null : (int) $t->customer,
            'proveedor' => $t->vendor === null ? null : (int) $t->vendor,
            'conceptos' => DB::table('charge')->where('transaction', $t->transc_id)->orderBy('charge_id')->get()
                ->map(fn ($c) => $c->description.'|'.$c->type.'|'.(int) $c->price)->all(),
        ])->all());
    }
}
