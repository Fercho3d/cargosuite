<?php

namespace Tests\Feature\Gps;

use App\Livewire\Fleet\FleetMap;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** El mapa de la flota: dónde está cada unidad, con filtros. */
class FleetMapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        config(['marca.modalidades' => 'terrestre', 'gps.sin_senal_minutos' => 15]);

        DB::table('operador')->insert(['operador_id' => 1, 'nombre' => 'Miguel Ramírez', 'activo' => 1]);
        DB::table('client')->insert(['client_id' => 1, 'fullName' => 'Cementos del Bajío']);
        DB::table('unidad')->insert([
            ['unidad_id' => 1, 'numero' => 'T-101', 'tipo' => 'tractor', 'activo' => 1],
            ['unidad_id' => 2, 'numero' => 'T-102', 'tipo' => 'tractor', 'activo' => 1],
            ['unidad_id' => 3, 'numero' => 'T-103', 'tipo' => 'tractor', 'activo' => 1],
            ['unidad_id' => 4, 'numero' => 'T-104', 'tipo' => 'tractor', 'activo' => 1],
        ]);
        DB::table('gps_dispositivo')->insert([
            // En movimiento, con viaje en curso.
            ['identificador' => 'A', 'unidad_id' => 1, 'activo' => 1, 'ultima_lat' => 25.68, 'ultima_lng' => -100.31, 'ultima_velocidad' => 82, 'ultima_senal' => now()->subMinutes(2)],
            // Detenida.
            ['identificador' => 'B', 'unidad_id' => 2, 'activo' => 1, 'ultima_lat' => 20.67, 'ultima_lng' => -103.35, 'ultima_velocidad' => 0, 'ultima_senal' => now()->subMinutes(3)],
            // Sin señal hace una hora.
            ['identificador' => 'C', 'unidad_id' => 3, 'activo' => 1, 'ultima_lat' => 19.43, 'ultima_lng' => -99.13, 'ultima_velocidad' => 60, 'ultima_senal' => now()->subHour()],
            // Nunca ha reportado: no se puede dibujar.
            ['identificador' => 'D', 'unidad_id' => 4, 'activo' => 1, 'ultima_lat' => null, 'ultima_lng' => null, 'ultima_velocidad' => null, 'ultima_senal' => null],
        ]);
        DB::table('booking')->insert([
            'booking_id' => 10, 'booking_number' => 'VJ-01010', 'client' => 1, 'unidad_id' => 1, 'operador_id' => 1,
            'is_draft' => 0, 'locked' => 0,
        ]);
    }

    private function mapa(): Testable
    {
        $usuario = User::forceCreate([
            'username' => 'trafico', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_USER, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);

        return Livewire::actingAs($usuario)->test(FleetMap::class);
    }

    /** @return list<string> */
    private function numeros(Testable $mapa): array
    {
        return collect($mapa->get('puntos'))->pluck('numero')->sort()->values()->all();
    }

    public function test_se_ven_las_unidades_que_ya_reportaron(): void
    {
        $this->assertSame(['T-101', 'T-102', 'T-103'], $this->numeros($this->mapa()));
    }

    public function test_cada_unidad_trae_su_viaje_y_operador(): void
    {
        $t101 = collect($this->mapa()->get('puntos'))->firstWhere('numero', 'T-101');

        $this->assertSame(['VJ-01010', 'Miguel Ramírez'], [$t101['viaje'], $t101['operador']]);
    }

    public function test_el_filtro_de_estado_separa_movimiento_detenidas_y_sin_senal(): void
    {
        $mapa = $this->mapa();

        $this->assertSame(
            [['T-101'], ['T-102'], ['T-103']],
            [
                $this->numeros($mapa->set('estado', 'movimiento')),
                $this->numeros($mapa->set('estado', 'detenida')),
                $this->numeros($mapa->set('estado', 'sin_senal')),
            ],
        );
    }

    public function test_se_busca_por_numero_de_unidad(): void
    {
        $this->assertSame(['T-102'], $this->numeros($this->mapa()->set('buscar', '102')));
    }

    public function test_se_filtra_a_las_que_van_en_viaje(): void
    {
        $this->assertSame(['T-101'], $this->numeros($this->mapa()->set('soloEnViaje', true)));
    }

    public function test_sin_autotransporte_no_hay_mapa(): void
    {
        config(['marca.modalidades' => 'maritimo']);

        $this->mapa()->assertNotFound();
    }
}
