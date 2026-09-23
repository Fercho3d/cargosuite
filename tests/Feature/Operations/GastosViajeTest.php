<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingDetail;
use App\Models\User;
use App\Support\Fleet\TripExpenses;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Gastos de carretera: combustible, casetas y lo demás.
 *
 * Es la mitad que le faltaba a la rentabilidad. El sistema ya sabía lo que se le
 * facturó al cliente y lo que costaron los proveedores, pero no lo que se gastó
 * en la carretera — que en autotransporte es donde se va el margen.
 */
class GastosViajeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        config(['marca.modalidades' => 'terrestre']);

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('unidad')->insert([['unidad_id' => 1, 'numero' => 'T-101', 'tipo' => 'tractor', 'activo' => 1]]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'VJ-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0, 'unidad_id' => 1,
        ]]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN): Testable
    {
        $usuario = User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);

        return Livewire::actingAs($usuario)->test(BookingDetail::class, ['booking' => 1]);
    }

    private function carga(float $litros, int $odometro, float $importe = 5000): void
    {
        DB::table('gasto_viaje')->insert([[
            'booking' => 1, 'tipo' => 'combustible', 'fecha' => '2026-06-01',
            'unidad_id' => 1, 'litros' => $litros, 'odometro' => $odometro, 'importe' => $importe,
        ]]);
    }

    public function test_los_gastos_suman_por_tipo_y_en_total(): void
    {
        DB::table('gasto_viaje')->insert([
            ['booking' => 1, 'tipo' => 'combustible', 'fecha' => '2026-06-01', 'importe' => 5000],
            ['booking' => 1, 'tipo' => 'caseta', 'fecha' => '2026-06-01', 'importe' => 1200],
            ['booking' => 1, 'tipo' => 'otro', 'fecha' => '2026-06-02', 'importe' => 300],
        ]);

        $t = TripExpenses::totales(1);

        $this->assertSame(5000.0, $t['combustible']);
        $this->assertSame(1200.0, $t['caseta']);
        $this->assertSame(6500.0, $t['total']);
    }

    /** El precio por litro se calcula, no se captura: así no puede no cuadrar. */
    public function test_el_precio_por_litro_sale_del_importe(): void
    {
        $this->pantalla()
            ->call('editGasto', null)
            ->set('gastoTipo', 'combustible')->set('gastoFecha', '2026-06-01')
            ->set('gastoLitros', '200')->set('gastoImporte', '5000')
            ->call('saveGasto')->assertHasNoErrors();

        $this->assertEqualsWithDelta(25.0, (float) DB::table('gasto_viaje')->value('precio_litro'), 0.001);
    }

    /** Una carga sin litros no sirve para nada: no se puede medir rendimiento. */
    public function test_el_combustible_exige_litros(): void
    {
        $this->pantalla()
            ->call('editGasto', null)
            ->set('gastoTipo', 'combustible')->set('gastoFecha', '2026-06-01')
            ->set('gastoImporte', '5000')
            ->call('saveGasto')->assertHasErrors('gastoLitros');
    }

    /** Una caseta no los pide. */
    public function test_una_caseta_no_pide_litros(): void
    {
        $this->pantalla()
            ->call('editGasto', null)
            ->set('gastoTipo', 'caseta')->set('gastoFecha', '2026-06-01')
            ->set('gastoImporte', '1200')
            ->call('saveGasto')->assertHasNoErrors();

        $this->assertNull(DB::table('gasto_viaje')->value('litros'));
    }

    /**
     * El rendimiento se mide contra la carga ANTERIOR de la misma unidad. Con
     * una sola no hay nada que comparar, y devolver un número ahí sería
     * inventarlo.
     */
    public function test_el_rendimiento_necesita_dos_cargas(): void
    {
        $this->carga(200, 100000);
        $primera = DB::table('gasto_viaje')->orderBy('gasto_id')->get()->last();
        $this->assertNull(TripExpenses::rendimiento($primera));

        $this->carga(150, 100600);
        $segunda = DB::table('gasto_viaje')->orderBy('gasto_id')->get()->last();

        // 600 km entre 150 litros = 4 km/L
        $this->assertSame(4.0, TripExpenses::rendimiento($segunda));
    }

    /** Un dedazo en el odómetro ensuciaría el promedio del mes: mejor sin número. */
    public function test_un_salto_absurdo_de_odometro_no_da_rendimiento(): void
    {
        $this->carga(200, 100000);
        $this->carga(150, 900000);

        $ultima = DB::table('gasto_viaje')->orderBy('gasto_id')->get()->last();

        $this->assertNull(TripExpenses::rendimiento($ultima));
    }

    /** Un viaje cerrado ya no recibe gastos: su facturación quedó fija. */
    public function test_un_viaje_cerrado_no_recibe_gastos(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['locked' => 1]);

        $this->pantalla()
            ->call('editGasto', null)
            ->set('gastoTipo', 'caseta')->set('gastoFecha', '2026-06-01')->set('gastoImporte', '100')
            ->call('saveGasto')->assertForbidden();
    }

    /** Y quien subcontrata el transporte no ve la sección: no paga diésel. */
    public function test_sin_flota_la_seccion_no_aparece(): void
    {
        config(['marca.modalidades' => 'maritimo']);

        $this->pantalla()->assertDontSee(__('Gastos del viaje'));
    }
}
