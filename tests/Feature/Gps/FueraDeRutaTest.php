<?php

namespace Tests\Feature\Gps;

use App\Actions\Gps\RegistraPosicion;
use App\Mail\FueraDeRutaMail;
use App\Support\Notifications\NoticeFeed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Una unidad que se sale de la ruta de su viaje.
 *
 * La ruta de prueba es la carretera recta de Monterrey hacia el sur (misma
 * longitud, -100.3); un punto a 0.1° de longitud queda a unos 10 km de ella.
 */
class FueraDeRutaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        Mail::fake();
        config(['gps.desvio_km' => 5, 'gps.desvio_lecturas' => 2, 'gps.alertas_correo' => true, 'marca.correo.avisos_operacion' => ['trafico@empresa.test']]);

        DB::table('unidad')->insert(['unidad_id' => 1, 'numero' => 'T-101', 'tipo' => 'tractor', 'activo' => 1]);
        DB::table('gps_dispositivo')->insert(['dispositivo_id' => 1, 'identificador' => 'A', 'unidad_id' => 1, 'activo' => 1]);
        DB::table('booking')->insert(['booking_id' => 44, 'booking_number' => 'VJ-01044', 'unidad_id' => 1, 'is_draft' => 0, 'locked' => 0]);
        DB::table('ruta_viaje')->insert([
            'booking_id' => 44, 'firma' => 'x', 'proveedor' => 'ors', 'calculada_en' => now(),
            'geometria' => json_encode([[25.7, -100.3], [25.3, -100.3], [24.9, -100.3]]),
        ]);
    }

    private function reporta(float $lat, float $lng, int $minuto): void
    {
        app(RegistraPosicion::class)('A', $lat, $lng, 70.0, 180, Carbon::parse('2026-09-24 10:00')->addMinutes($minuto));
    }

    private function abiertas(): int
    {
        return DB::table('gps_alerta')->whereNull('fin')->count();
    }

    public function test_sobre_la_ruta_no_hay_alerta(): void
    {
        $this->reporta(25.5, -100.301, 0);
        $this->reporta(25.4, -100.302, 1);

        $this->assertSame(0, $this->abiertas());
    }

    /** Una sola lectura lejos puede ser un mal dato del GPS: todavía no se avisa. */
    public function test_una_sola_lectura_fuera_no_alcanza(): void
    {
        $this->reporta(25.5, -100.2, 0);

        $this->assertSame(0, $this->abiertas());
    }

    public function test_dos_lecturas_seguidas_fuera_abren_la_alerta(): void
    {
        $this->reporta(25.5, -100.2, 0);
        $this->reporta(25.45, -100.19, 1);

        $this->assertSame(1, $this->abiertas());
    }

    public function test_la_alerta_se_manda_por_correo_una_sola_vez(): void
    {
        foreach (range(0, 4) as $minuto) {
            $this->reporta(25.5, -100.2, $minuto);
        }

        Mail::assertSent(FueraDeRutaMail::class, 1);
    }

    /** El correo se puede apagar en Ajustes: la alerta sigue en el mapa y la campana. */
    public function test_con_el_correo_apagado_la_alerta_se_abre_sin_mandar_correo(): void
    {
        config(['gps.alertas_correo' => false]);

        $this->reporta(25.5, -100.2, 0);
        $this->reporta(25.45, -100.19, 1);

        Mail::assertNothingSent();
        $this->assertSame(1, $this->abiertas());
    }

    public function test_al_volver_a_la_ruta_la_alerta_se_cierra(): void
    {
        $this->reporta(25.5, -100.2, 0);
        $this->reporta(25.45, -100.19, 1);
        $this->reporta(25.4, -100.301, 2);

        $this->assertSame([0, 1], [$this->abiertas(), DB::table('gps_alerta')->whereNotNull('fin')->count()]);
    }

    public function test_guarda_la_mayor_distancia_a_la_que_llego(): void
    {
        $this->reporta(25.5, -100.2, 0);
        $this->reporta(25.45, -100.1, 1);
        $this->reporta(25.4, -100.15, 2);

        $this->assertEqualsWithDelta(20.1, (float) DB::table('gps_alerta')->value('distancia_km'), 0.5);
    }

    /** Sin ruta calculada no hay contra qué comparar: no se inventa una alerta. */
    public function test_sin_ruta_no_hay_alerta(): void
    {
        DB::table('ruta_viaje')->delete();

        $this->reporta(25.5, -100.2, 0);
        $this->reporta(25.45, -100.19, 1);

        $this->assertSame(0, $this->abiertas());
    }

    /** Si el correo falla (servidor caído, llave sin permiso), la posición y la alerta se guardan igual. */
    public function test_un_correo_que_falla_no_tumba_la_entrada_de_posiciones(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('550 no autorizado'));

        $this->reporta(25.5, -100.2, 0);
        $this->reporta(25.45, -100.19, 1);

        $this->assertSame([2, 1], [DB::table('gps_posicion')->count(), $this->abiertas()]);
    }

    public function test_la_alerta_sale_en_la_campana(): void
    {
        Cache::flush();
        $this->reporta(25.5, -100.2, 0);
        $this->reporta(25.45, -100.19, 1);

        $this->assertTrue(app(NoticeFeed::class)->all()->contains(fn ($a) => str_contains($a->titulo, 'T-101')));
    }
}
