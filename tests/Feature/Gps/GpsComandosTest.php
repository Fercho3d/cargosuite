<?php

namespace Tests\Feature\Gps;

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
}
