<?php

namespace Tests\Feature;

use App\Support\Dashboard\RouteMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * El mapa de rutas del panel.
 *
 * ⚠️ Lo que dibuja es una **estimación por fechas**, no un rastreo: el sistema no
 * sabe dónde está el barco y no lo va a saber sin contratar AIS. Estas pruebas
 * fijan justo eso — que la posición sale de las fechas — para que nadie lo
 * confunda más adelante con un dato real.
 */
class MapaRutasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();

        Carbon::setTestNow('2026-06-15 12:00:00');

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('loading_ports')->insert([[
            'port_id' => 1, 'port_name' => 'Puerto Centro', 'deleted' => 0,
            'latitud' => 19.053, 'longitud' => -104.315,
        ]]);
        DB::table('dicharge_port')->insert([[
            'dicharge_port_id' => 1, 'name' => 'Rotterdam', 'deleted' => 0,
            'latitud' => 51.949, 'longitud' => 4.143,
        ]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function embarque(array $cambios = []): void
    {
        DB::table('booking')->insert([array_merge([
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0,
            'loading_port' => 1, 'dicharge_port_id' => 1,
            'loading_EDT' => '2026-06-01', 'dicharge_ETA' => '2026-07-01',
        ], $cambios)]);
    }

    public function test_la_proyeccion_pone_cada_punto_donde_toca(): void
    {
        // Greenwich y el ecuador caen en el centro exacto del lienzo.
        $this->assertSame(
            ['x' => (float) (RouteMap::ANCHO / 2), 'y' => (float) (RouteMap::ALTO / 2)],
            RouteMap::punto(0, 0),
        );

        $this->assertSame(['x' => 0.0, 'y' => 0.0], RouteMap::punto(90, -180));
    }

    /** A mitad de camino en el tiempo, el medio va a mitad de camino. */
    public function test_la_posicion_sale_de_las_fechas(): void
    {
        $this->embarque();

        $ruta = RouteMap::rutas()[0];

        $this->assertEqualsWithDelta(0.5, $ruta['avance'], 0.02);
        $this->assertSame('Puerto Centro', $ruta['origen']['nombre']);
        $this->assertSame('Rotterdam', $ruta['destino']['nombre']);
    }

    public function test_antes_de_zarpar_esta_en_el_origen_y_al_llegar_en_el_destino(): void
    {
        $this->embarque(['loading_EDT' => '2026-06-20', 'dicharge_ETA' => '2026-07-20']);
        $this->assertSame(0.0, RouteMap::rutas()[0]['avance']);

        DB::table('booking')->where('booking_id', 1)
            ->update(['loading_EDT' => '2026-05-01', 'dicharge_ETA' => '2026-05-20']);
        $this->assertSame(1.0, RouteMap::rutas()[0]['avance']);
    }

    /**
     * Sin coordenadas no se dibuja: no se inventa una posición para rellenar el
     * mapa, que es lo que haría que alguien confiara en un punto falso.
     */
    public function test_un_lugar_sin_coordenadas_no_se_dibuja(): void
    {
        $this->embarque();
        DB::table('dicharge_port')->where('dicharge_port_id', 1)->update(['latitud' => null, 'longitud' => null]);

        $this->assertSame([], RouteMap::rutas());
    }

    /** Sin fecha de arribo se queda en el origen, no a medio océano. */
    public function test_sin_fecha_de_arribo_no_se_reparte_a_ojo(): void
    {
        $this->embarque(['dicharge_ETA' => null]);

        $ruta = RouteMap::rutas()[0];

        $this->assertSame(0.0, $ruta['avance']);
        $this->assertSame($ruta['origen']['x'], $ruta['posicion']['x']);
    }

    /** Los borradores y las cotizaciones no son operación en curso. */
    public function test_los_borradores_no_salen(): void
    {
        $this->embarque(['is_draft' => 1]);

        $this->assertSame([], RouteMap::rutas());
    }

    public function test_el_panel_avisa_de_que_la_posicion_es_estimada(): void
    {
        $this->embarque();

        $vista = view('partials.mapa-rutas', ['rutas' => RouteMap::rutas()])->render();

        $this->assertStringContainsString('estimada', $vista);
        $this->assertStringContainsString('<svg', $vista);
    }
}
