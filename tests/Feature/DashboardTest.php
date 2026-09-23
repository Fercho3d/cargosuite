<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\User;
use App\Support\Dashboard\DashboardMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** El panel: las cifras del mes y lo que está esperando a alguien. */
class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1]]);
        DB::table('charge_type')->insert([['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0]]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Frialsa']]);
        DB::table('exchange')->insert([[
            'exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => now()->toDateString(), 'account' => 1,
        ]]);
    }

    /** Un booking del mes con su factura y sus contenedores. */
    private function mesConMovimiento(): void
    {
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0, 'loading_EDT' => now()->toDateString(),
            'dicharge_ETA' => now()->addDays(5)->toDateString(),
        ]]);
        DB::table('containers')->insert([[
            'container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 3,
        ]]);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
            'tran_number' => 'F-1', 'tran_date' => now()->toDateString(), 'invoice_type' => 1,
        ]]);
        DB::table('charge')->insert([[
            'charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 1, 'price' => 1000,
        ]]);
    }

    private function usuario(): User
    {
        return User::forceCreate([
            'username' => 'operador', 'name' => 'Ana Ruiz',
            'password' => 'secreto-de-prueba', 'role' => User::ROLE_ADMIN, 'status' => 1,
        ]);
    }

    public function test_las_cifras_son_las_del_mes_en_curso(): void
    {
        $this->mesConMovimiento();

        $panel = app(DashboardMetrics::class)->all(esAdmin: true);

        $this->assertSame(1000.0, $panel['facturado']);
        $this->assertSame(1, $panel['embarques']);
        $this->assertSame(3, $panel['contenedores']);
    }

    public function test_lo_del_mes_pasado_no_cuenta(): void
    {
        $this->mesConMovimiento();
        DB::table('transaction')->where('transc_id', 1)->update(['tran_date' => now()->subMonth()->toDateString()]);
        DB::table('booking')->where('booking_id', 1)->update(['loading_EDT' => now()->subMonth()->toDateString()]);

        $panel = app(DashboardMetrics::class)->all(esAdmin: true);

        $this->assertSame(0.0, $panel['facturado']);
        $this->assertSame(0, $panel['embarques']);
    }

    public function test_cuenta_las_facturas_que_faltan_por_timbrar(): void
    {
        $this->mesConMovimiento();

        $this->assertSame(1, app(DashboardMetrics::class)->all(esAdmin: true)['pendientes']['timbrar']);

        DB::table('transaction')->where('transc_id', 1)->update(['seal' => 'UUID-1']);
        Cache::flush();

        $this->assertSame(0, app(DashboardMetrics::class)->all(esAdmin: true)['pendientes']['timbrar']);
    }

    public function test_ensena_lo_que_viene(): void
    {
        $this->mesConMovimiento();

        Livewire::actingAs($this->usuario())
            ->test(Dashboard::class)
            ->assertSee('BK-1')
            ->assertSee('Frialsa')
            ->assertSee('Próximos movimientos');
    }

    public function test_la_grafica_trae_un_punto_por_mes(): void
    {
        $panel = app(DashboardMetrics::class)->all(esAdmin: true);

        $this->assertCount(6, $panel['serie']);
        $this->assertArrayHasKey(now()->format('Y-m'), $panel['serie']);
    }

    /**
     * Lo que se guarda en caché tiene que poder releerse con el driver que
     * serializa. Es la prueba que faltó las dos veces que la caché tumbó el
     * sistema: se guardaban modelos de Eloquent y volvían rotos.
     */
    public function test_lo_cacheado_son_datos_planos_y_se_relee(): void
    {
        $ruta = storage_path('framework/testing/cache-panel-'.getmypid());

        config(['cache.default' => 'file', 'cache.stores.file.path' => $ruta]);

        $this->mesConMovimiento();

        $primera = app(DashboardMetrics::class)->all(esAdmin: true);
        $segunda = app(DashboardMetrics::class)->all(esAdmin: true);

        $this->assertEquals($primera, $segunda);
        $this->assertPlano($primera);

        File::deleteDirectory($ruta);
    }

    /**
     * Los dos pendientes enlazan a facturación, que es solo de administradores.
     * Si se calcularan para todos, el panel les pondría enlaces que responden
     * 403 —y la caché de cinco minutos podría servirle a un rol la foto del otro.
     */
    public function test_los_pendientes_son_solo_para_administradores(): void
    {
        $this->mesConMovimiento();

        $this->assertArrayHasKey('pendientes', app(DashboardMetrics::class)->all(esAdmin: true));
        $this->assertArrayNotHasKey('pendientes', app(DashboardMetrics::class)->all(esAdmin: false));
    }

    public function test_el_panel_de_quien_no_es_admin_no_enlaza_a_facturacion(): void
    {
        $this->mesConMovimiento();

        $operacion = User::forceCreate([
            'username' => 'chofer', 'name' => 'Luis Mena',
            'password' => 'secreto-de-prueba', 'role' => User::ROLE_USER, 'status' => 1,
        ]);

        $this->assertFalse($operacion->isAdmin());

        Livewire::actingAs($operacion)
            ->test(Dashboard::class)
            ->assertDontSee(route('transactions.invoice'))
            ->assertDontSee(route('payments.requests'));
    }

    /** @param  array<string, mixed>  $datos */
    private function assertPlano(array $datos): void
    {
        array_walk_recursive($datos, function ($valor) {
            $this->assertTrue(
                $valor === null || is_scalar($valor),
                'La caché del panel solo debe guardar números y textos: llegó un '.get_debug_type($valor).'.',
            );
        });
    }
}
