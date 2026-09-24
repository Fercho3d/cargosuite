<?php

namespace Tests\Feature\Gps;

use Illuminate\Support\Facades\DB;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * La API por la que llegan las posiciones de los GPS.
 *
 * Dos formatos: el JSON que reenvía un servidor Traccar (que ya tradujo el
 * protocolo del fabricante) y el de la app Traccar Client del celular
 * (protocolo OsmAnd, por parámetros). Traccar manda la velocidad en nudos.
 */
class GpsApiTest extends TestCase
{
    private const IMEI = '356307042441013';

    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        config(['gps.token' => 'secreto']);

        DB::table('unidad')->insert(['unidad_id' => 7, 'numero' => 'T-107', 'tipo' => 'tractor', 'activo' => 1]);
        DB::table('gps_dispositivo')->insert(['identificador' => self::IMEI, 'unidad_id' => 7, 'activo' => 1]);
    }

    private function traccar(array $posicion = [], string $imei = self::IMEI, string $token = 'secreto')
    {
        return $this->postJson('/api/gps/posiciones?token='.$token, [
            'device' => ['uniqueId' => $imei, 'name' => 'T-107'],
            'position' => $posicion + [
                'latitude' => 25.6866, 'longitude' => -100.3161, 'speed' => 10.0, 'course' => 90,
                'fixTime' => '2026-09-24T15:00:00.000+00:00',
            ],
        ]);
    }

    public function test_traccar_reenvia_una_posicion(): void
    {
        $this->traccar()->assertOk();

        $this->assertSame(1, DB::table('gps_posicion')->where('unidad_id', 7)->count());
    }

    public function test_la_velocidad_de_traccar_llega_en_nudos_y_se_guarda_en_kmh(): void
    {
        $this->traccar(['speed' => 10.0]);

        $this->assertSame(18.5, round((float) DB::table('gps_posicion')->value('velocidad'), 1));
    }

    public function test_actualiza_la_ultima_posicion_del_equipo(): void
    {
        $this->traccar();

        $this->assertEqualsWithDelta(25.6866, (float) DB::table('gps_dispositivo')->value('ultima_lat'), 0.00001);
    }

    /** Los equipos reenvían en desorden cuando recuperan señal. */
    public function test_una_posicion_vieja_no_pisa_la_ultima(): void
    {
        $this->traccar(['fixTime' => '2026-09-24T15:00:00+00:00', 'latitude' => 25.0]);
        $this->traccar(['fixTime' => '2026-09-24T14:00:00+00:00', 'latitude' => 20.0]);

        $this->assertEqualsWithDelta(25.0, (float) DB::table('gps_dispositivo')->value('ultima_lat'), 0.00001);
    }

    /**
     * Los equipos mandan la hora en UTC y el sistema trabaja en hora de México:
     * guardada en UTC, la última señal saldría «en 6 horas» y el filtro de
     * «sin señal» nunca se cumpliría.
     */
    public function test_la_hora_del_equipo_se_guarda_en_hora_local(): void
    {
        $this->traccar(['fixTime' => '2026-09-24T15:00:00+00:00']);

        $this->assertSame('2026-09-24 09:00:00', (string) DB::table('gps_posicion')->value('fecha'));
    }

    public function test_sin_la_clave_no_se_escribe(): void
    {
        $this->traccar(token: 'otra')->assertUnauthorized();
    }

    /** Sin GPS_TOKEN la API está cerrada: ni con la clave vacía se escribe. */
    public function test_con_la_api_cerrada_nadie_escribe(): void
    {
        config(['gps.token' => '']);

        $this->traccar(token: '')->assertUnauthorized();
    }

    /**
     * Un equipo que nadie dio de alta se ignora con un 202: si se respondiera
     * con error, Traccar lo volvería a mandar una y otra vez.
     */
    public function test_un_equipo_desconocido_no_se_guarda(): void
    {
        $this->traccar(imei: '000000000000000')->assertAccepted();

        $this->assertSame(0, DB::table('gps_posicion')->count());
    }

    public function test_una_coordenada_imposible_se_rechaza(): void
    {
        $this->traccar(['latitude' => 95.0])->assertUnprocessable();
    }

    public function test_la_app_del_celular_manda_su_posicion(): void
    {
        $this->get('/api/gps/osmand?token=secreto&id='.self::IMEI.'&lat=20.6597&lon=-103.3496&timestamp=1790000000&speed=0&bearing=45')
            ->assertOk();

        $this->assertSame(1, DB::table('gps_posicion')->count());
    }

    /** La app agrega `?id=…` a la dirección: la clave puede ir en la ruta. */
    public function test_la_clave_de_la_app_puede_ir_en_la_ruta(): void
    {
        $this->get('/api/gps/osmand/secreto?id='.self::IMEI.'&lat=20.6597&lon=-103.3496')->assertOk();
    }
}
