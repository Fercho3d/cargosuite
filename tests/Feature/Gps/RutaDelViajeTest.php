<?php

namespace Tests\Feature\Gps;

use App\Support\Gps\RutaDelViaje;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * La ruta de un viaje: la planeada (por carretera, del servicio de rutas) y el
 * recorrido real (lo que mandó el GPS). No se sale a internet: se fingen las
 * respuestas de OpenRouteService y OSRM.
 */
class RutaDelViajeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'Patio Monterrey', 'latitud' => 25.686, 'longitud' => -100.316]);
        DB::table('dicharge_port')->insert(['dicharge_port_id' => 1, 'name' => 'Ciudad de México', 'latitud' => 19.432, 'longitud' => -99.133]);
        DB::table('unidad')->insert(['unidad_id' => 1, 'numero' => 'T-101', 'tipo' => 'tractor', 'activo' => 1]);
        DB::table('booking')->insert([
            'booking_id' => 44, 'booking_number' => 'VJ-01044', 'loading_port' => 1, 'dicharge_port_id' => 1,
            'unidad_id' => 1, 'loading_EDT' => now()->subDay()->toDateString(),
        ]);
    }

    private function ors(): void
    {
        config(['gps.rutas.proveedor' => 'ors', 'gps.rutas.ors_key' => 'clave-ors']);
        Http::fake(['api.openrouteservice.org/*' => Http::response([
            'features' => [[
                'geometry' => ['coordinates' => [[-100.316, 25.686], [-100.9, 22.15], [-99.133, 19.432]]],
                'properties' => ['summary' => ['distance' => 912345.0, 'duration' => 39600.0]],
            ]],
        ])]);
    }

    public function test_la_ruta_planeada_sigue_la_carretera_del_servicio(): void
    {
        $this->ors();

        $ruta = app(RutaDelViaje::class)->planeada(44);

        $this->assertSame([[25.686, -100.316], [22.15, -100.9], [19.432, -99.133]], $ruta['puntos']);
    }

    public function test_trae_la_distancia_y_el_tiempo(): void
    {
        $this->ors();

        $ruta = app(RutaDelViaje::class)->planeada(44);

        $this->assertSame([912.3, 660], [$ruta['km'], $ruta['minutos']]);
    }

    /** OpenRouteService con el perfil de camión de carga y su clave. */
    public function test_pide_la_ruta_de_camion_de_carga(): void
    {
        $this->ors();

        app(RutaDelViaje::class)->planeada(44);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'driving-hgv') && $r->header('Authorization') === ['clave-ors']);
    }

    /** Se calcula una sola vez por viaje: el servicio cobra o limita por consulta. */
    public function test_la_ruta_se_guarda_y_no_se_vuelve_a_pedir(): void
    {
        $this->ors();

        app(RutaDelViaje::class)->planeada(44);
        app(RutaDelViaje::class)->planeada(44);

        Http::assertSentCount(1);
    }

    /** Una ruta real trae miles de puntos; al mapa le bastan unos cientos. */
    public function test_una_ruta_larga_se_recorta_sin_perder_sus_extremos(): void
    {
        config(['gps.rutas.proveedor' => 'osrm', 'gps.rutas.osrm_url' => 'https://osrm.test']);
        $puntos = array_map(fn ($i) => [-100.3 + $i / 10000, 25.6 - $i / 10000], range(0, 4999));
        Http::fake(['osrm.test/*' => Http::response(['routes' => [['geometry' => ['coordinates' => $puntos], 'distance' => 1.0, 'duration' => 1.0]]])]);

        $ruta = app(RutaDelViaje::class)->planeada(44)['puntos'];

        $this->assertSame([true, [25.6, -100.3], [25.1001, -99.8001]], [count($ruta) <= 601, $ruta[0], end($ruta)]);
    }

    public function test_tambien_sirve_con_osrm(): void
    {
        config(['gps.rutas.proveedor' => 'osrm', 'gps.rutas.osrm_url' => 'https://osrm.test']);
        Http::fake(['osrm.test/*' => Http::response([
            'routes' => [['geometry' => ['coordinates' => [[-100.316, 25.686], [-99.133, 19.432]]], 'distance' => 900000.0, 'duration' => 36000.0]],
        ])]);

        $this->assertSame(900.0, app(RutaDelViaje::class)->planeada(44)['km']);
    }

    /** Si el servicio falla, línea recta marcada como aproximada, y no se guarda. */
    public function test_sin_servicio_la_ruta_es_una_linea_recta_aproximada(): void
    {
        config(['gps.rutas.proveedor' => 'osrm']);
        Http::fake(['*' => Http::response('error', 500)]);

        $ruta = app(RutaDelViaje::class)->planeada(44);

        $this->assertSame([true, 2, 0], [$ruta['aproximada'], count($ruta['puntos']), DB::table('ruta_viaje')->count()]);
    }

    public function test_sin_coordenadas_no_hay_ruta(): void
    {
        DB::table('dicharge_port')->update(['latitud' => null, 'longitud' => null]);

        $this->assertNull(app(RutaDelViaje::class)->planeada(44));
    }

    /** El recorrido real: lo que mandó el GPS de la unidad desde la carga. */
    public function test_el_recorrido_son_las_posiciones_de_la_unidad_desde_la_carga(): void
    {
        foreach ([[now()->subDays(3), 20.0], [now()->subHours(2), 25.6], [now()->subHour(), 24.8]] as [$fecha, $lat]) {
            DB::table('gps_posicion')->insert([
                'dispositivo_id' => 1, 'unidad_id' => 1, 'lat' => $lat, 'lng' => -100.3, 'fecha' => $fecha, 'recibido_en' => $fecha,
            ]);
        }

        $this->assertSame([[25.6, -100.3], [24.8, -100.3]], app(RutaDelViaje::class)->recorrido(44));
    }
}
