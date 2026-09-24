<?php

namespace Tests\Feature\Gps;

use App\Support\Gps\VigilaRuta;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** La depuración del historial y el simulador de la demostración. */
class GpsComandosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        DB::table('unidad')->insert(['unidad_id' => 1, 'numero' => 'T-101', 'tipo' => 'tractor', 'activo' => 1]);
        DB::table('gps_dispositivo')->insert([
            'dispositivo_id' => 1, 'identificador' => 'DEMO-1', 'unidad_id' => 1, 'activo' => 1,
            'ultima_lat' => 25.6866, 'ultima_lng' => -100.3161, 'ultima_senal' => now()->subMinutes(5),
        ]);
    }

    public function test_la_depuracion_borra_solo_lo_que_paso_la_retencion(): void
    {
        config(['gps.retencion_dias' => 90]);
        foreach ([now()->subDays(120), now()->subDays(10)] as $fecha) {
            DB::table('gps_posicion')->insert([
                'dispositivo_id' => 1, 'unidad_id' => 1, 'lat' => 25, 'lng' => -100, 'fecha' => $fecha, 'recibido_en' => $fecha,
            ]);
        }

        $this->artisan('gps:depura')->assertSuccessful();

        $this->assertSame(1, DB::table('gps_posicion')->count());
    }

    /** En la demostración las unidades se mueven solas: si no, a los 15 minutos todas salen «sin señal». */
    public function test_el_simulador_mueve_las_unidades_de_la_demostracion(): void
    {
        config(['marca.demo' => true]);

        $this->artisan('gps:simula-demo')->assertSuccessful();

        $this->assertTrue(now()->subMinute()->lt(DB::table('gps_dispositivo')->value('ultima_senal')));
    }

    /** En la instalación de un cliente el simulador no toca nada. */
    public function test_sin_demostracion_el_simulador_no_hace_nada(): void
    {
        config(['marca.demo' => false]);

        $this->artisan('gps:simula-demo');

        $this->assertSame(0, DB::table('gps_posicion')->count());
    }

    /** Con viaje, la unidad de la demostración avanza sobre la carretera de su ruta. */
    public function test_el_simulador_mueve_la_unidad_sobre_la_ruta_de_su_viaje(): void
    {
        config(['marca.demo' => true, 'gps.rutas.proveedor' => 'ninguno']);
        $ruta = [[25.6866, -100.3161], [25.60, -100.35], [25.50, -100.40], [25.40, -100.45]];
        DB::table('booking')->insert(['booking_id' => 44, 'booking_number' => 'VJ-01044', 'unidad_id' => 1, 'is_draft' => 0, 'locked' => 0]);
        DB::table('ruta_viaje')->insert([
            'booking_id' => 44, 'firma' => 'x', 'proveedor' => 'osrm', 'geometria' => json_encode($ruta), 'calculada_en' => now(),
        ]);

        $this->artisan('gps:simula-demo');

        $posicion = DB::table('gps_dispositivo')->first(['ultima_lat', 'ultima_lng']);
        $this->assertContains([round((float) $posicion->ultima_lat, 4), round((float) $posicion->ultima_lng, 4)], array_map(
            fn ($p) => [round($p[0], 4), round($p[1], 4)], $ruta,
        ));
    }

    /**
     * En la demostración, T-104 se sale de su ruta los últimos 20 minutos de
     * cada hora: así la alerta de «fuera de ruta» se ve abrirse y cerrarse.
     */
    public function test_en_la_demostracion_una_unidad_se_desvia_a_ratos(): void
    {
        config(['marca.demo' => true, 'gps.rutas.proveedor' => 'ninguno']);
        $this->travelTo(now()->setTime(10, 45));
        $ruta = [[25.6866, -100.3161], [25.60, -100.35], [25.50, -100.40], [25.40, -100.45]];
        DB::table('unidad')->insert(['unidad_id' => 4, 'numero' => 'T-104', 'tipo' => 'tractor', 'activo' => 1]);
        DB::table('gps_dispositivo')->insert([
            'dispositivo_id' => 4, 'identificador' => 'DEMO-4', 'unidad_id' => 4, 'activo' => 1,
            'ultima_lat' => 25.6866, 'ultima_lng' => -100.3161, 'ultima_senal' => now()->subMinute(),
        ]);
        DB::table('booking')->insert(['booking_id' => 43, 'booking_number' => 'VJ-01043', 'unidad_id' => 4, 'is_draft' => 0, 'locked' => 0]);
        DB::table('ruta_viaje')->insert([
            'booking_id' => 43, 'firma' => 'x', 'proveedor' => 'osrm', 'geometria' => json_encode($ruta), 'calculada_en' => now(),
        ]);

        $this->artisan('gps:simula-demo');

        $p = DB::table('gps_dispositivo')->where('dispositivo_id', 4)->first();
        $this->assertGreaterThan(5, VigilaRuta::distanciaKm($ruta, (float) $p->ultima_lat, (float) $p->ultima_lng));
    }
}
