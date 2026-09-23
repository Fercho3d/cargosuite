<?php

namespace Tests\Feature;

use App\Support\Expediente;
use App\Support\Workshop\Inventory;
use App\Support\Workshop\Maintenance;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\DemoDatabaseTestCase;

/**
 * La demostración de autotransporte se sostiene delante de un transportista.
 *
 * Un 200 no basta aquí: lo que se revisa es que los NÚMEROS tengan sentido para
 * quien mueve camiones en México. Un flete en dólares, un tránsito de treinta
 * días o un viaje de Monterrey a Monterrey no rompen nada —y hunden la reunión—.
 *
 *   php artisan test --group=demo
 */
#[Group('demo')]
class DemoCamionesTest extends DemoDatabaseTestCase
{
    protected function base(): string
    {
        return 'cargosuite_camiones';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // La base está sembrada con el perfil de camiones; las pantallas se
        // acomodan por la modalidad, que en la corrida de pruebas no está puesta.
        Config::set('marca.modalidades', 'terrestre');
        Config::set('marca.vocabulario', 'camiones');
        Config::set('marca.nomina', true);
        Config::set('marca.taller', true);
        Expediente::olvida();
    }

    public function test_abren_las_pantallas_de_autotransporte(): void
    {
        $ultimo = (int) DB::table('booking')->max('booking_id');

        $this->abre([
            '/dashboard', '/operacion/bookings', '/operacion/bookings/1',
            "/operacion/bookings/{$ultimo}", '/operacion/continuidad',
            '/pagos/liquidaciones', '/pagos/nomina',
            '/catalogos/operadores', '/catalogos/unidades', '/catalogos/empleados', '/catalogos/refacciones',
            '/taller/mantenimiento', '/taller/almacen',
            '/transacciones', '/transacciones/costos', '/transacciones/reporte/booking',
        ]);
    }

    /** El flete va en pesos: una tarifa por kilómetro no se cotiza en dólares. */
    public function test_se_factura_en_pesos(): void
    {
        $enDolares = DB::table('transaction')->where('tran_type', 0)->where('account', 2)->count();

        $this->assertSame(0, $enDolares, 'Hay facturas de flete terrestre en dólares.');
    }

    /**
     * El flete lleva retención del 4 %.
     *
     * Es lo que hace el autotransporte de carga en México con un cliente persona
     * moral, y lo primero que busca en la factura quien factura fletes.
     */
    public function test_el_flete_lleva_la_retencion_del_cuatro_por_ciento(): void
    {
        $flete = DB::table('charge')
            ->join('transaction as t', 't.transc_id', '=', 'charge.transaction')
            ->join('charge_type as ct', 'ct.charge_type_id', '=', 'charge.type')
            ->where('t.tran_type', 0)
            ->where('charge.type', 1)
            ->first(['ct.charge_type_name', 'ct.tax_rate', 'ct.tax_retention']);

        $this->assertNotNull($flete, 'Ninguna factura trae flete.');
        $this->assertEqualsWithDelta(0.16, (float) $flete->tax_rate, 0.001);
        $this->assertEqualsWithDelta(0.04, (float) $flete->tax_retention, 0.001);
    }

    /** Un camión no tarda semanas: el tránsito sale de los kilómetros. */
    public function test_los_transitos_son_de_dias_y_no_de_semanas(): void
    {
        $largos = DB::table('booking')
            ->whereRaw('DATEDIFF(dicharge_ETA, loading_EDT) > 5')
            ->count();

        $this->assertSame(0, $largos, 'Hay viajes terrestres de más de cinco días.');
    }

    /** Nadie contrata un flete de Monterrey a Monterrey. */
    public function test_ninguna_ruta_empieza_y_acaba_en_la_misma_ciudad(): void
    {
        $absurdas = DB::table('booking as b')
            ->join('loading_ports as o', 'o.port_id', '=', 'b.loading_port')
            ->join('dicharge_port as d', 'd.dicharge_port_id', '=', 'b.dicharge_port_id')
            // Los orígenes son «Patio <ciudad>» y los destinos la ciudad pelada.
            ->whereRaw("o.port_name = CONCAT('Patio ', d.name)")
            ->count();

        $this->assertSame(0, $absurdas, 'Hay viajes que empiezan y terminan en la misma ciudad.');
    }

    /**
     * El diésel que se paga y el que se registra como gasto son el mismo.
     *
     * Si el módulo de gastos y la contabilidad cuentan litros distintos, el
     * rendimiento por unidad —lo que de verdad interesa a un transportista— sale
     * de un número inventado.
     */
    public function test_el_diesel_del_gasto_cuadra_con_el_del_costo(): void
    {
        $viaje = DB::table('gasto_viaje')->where('tipo', 'combustible')->orderBy('gasto_id')->first();

        $this->assertNotNull($viaje, 'Ningún viaje trae carga de diésel.');

        $costo = DB::table('charge')
            ->join('transaction as t', 't.transc_id', '=', 'charge.transaction')
            ->where('t.booking', $viaje->booking)
            ->where('t.tran_type', 1)
            ->where('charge.description', 'Diésel del viaje')
            ->first(['charge.quantity', 'charge.price']);

        $this->assertNotNull($costo, 'El viaje con diésel no tiene su costo de diésel.');
        $this->assertEqualsWithDelta((float) $viaje->litros, (float) $costo->quantity, 0.1);
        $this->assertEqualsWithDelta((float) $viaje->precio_litro, (float) $costo->price, 0.01);
    }

    /** Con flota propia hay quien maneje; subcontratado, no. */
    public function test_los_viajes_propios_traen_operador_y_los_subcontratados_no(): void
    {
        $this->assertGreaterThan(0, DB::table('booking')->whereNotNull('operador_id')->count());

        $subcontratados = DB::table('booking')->whereNull('operador_id')->pluck('booking_id');

        $this->assertGreaterThan(0, $subcontratados->count(), 'Ningún viaje se subcontrata.');

        $this->assertSame(0, DB::table('gasto_viaje')->whereIn('booking', $subcontratados)->count(),
            'Un viaje subcontratado no gasta diésel: el camión no era nuestro.');
    }

    /**
     * El almacén cuadra: la existencia es la suma del kárdex.
     *
     * Es lo único que hunde a un almacén, y en los datos de ejemplo tiene que
     * cuadrar desde el primer día: si la demostración arranca descuadrada, lo
     * primero que aprende quien la mira es a desconfiar del número.
     */
    public function test_el_almacen_cuadra(): void
    {
        $this->assertGreaterThan(0, DB::table('refaccion')->count(), 'El almacén está vacío.');
        $this->assertSame([], Inventory::descuadres()->all());

        // Y nada en negativo: el módulo lo permite —en un taller la pieza se
        // pone y el papel llega después— pero en una demostración una existencia
        // negativa se lee como un error del sistema.
        $this->assertSame(0, DB::table('refaccion')->where('existencia', '<', 0)->count());
    }

    /** Y enseña lo que se usa: qué falta y qué unidad ya debe servicio. */
    public function test_el_taller_tiene_algo_que_ensenar(): void
    {
        $this->assertGreaterThan(0, Inventory::bajoMinimo()->count(),
            'Ninguna refacción está por reponerse: el aviso del almacén saldría vacío.');

        $this->assertGreaterThan(0, Maintenance::porServicio()->count(),
            'Ninguna unidad debe servicio: el aviso del taller saldría vacío.');

        $this->assertGreaterThan(0, DB::table('mantenimiento')->count());
    }

    /** La demostración se ve operable: hay dinero cobrado y por cobrar. */
    public function test_hay_facturas_cobradas_y_pendientes(): void
    {
        $this->assertGreaterThan(0, DB::table('transaction')->where('tran_type', 0)->where('paid', 1)->count());
        $this->assertGreaterThan(0, DB::table('transaction')->where('tran_type', 0)->where('paid', 0)->count());
        $this->assertGreaterThan(0, DB::table('nomina_renglon')->count());
    }
}
