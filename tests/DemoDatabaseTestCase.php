<?php

namespace Tests;

use App\Models\User;
use App\Support\Dashboard\DashboardMetrics;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base de las pruebas que corren contra una base de demostración sembrada.
 *
 * Van fuera de la corrida normal (`--group=demo`) porque necesitan MySQL con la
 * base ya llena; sin ella se saltan en vez de fallar.
 *
 * Existen porque un dato de ejemplo mal puesto no rompe nada: solo deja
 * pantallas vacías, y eso, delante de un cliente, es peor que un error.
 */
abstract class DemoDatabaseTestCase extends TestCase
{
    /** La base sembrada que se revisa. */
    abstract protected function base(): string;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.demo', array_merge(
            config('database.connections.frego_legacy'),
            ['database' => $this->base()],
        ));
        Config::set('database.default', 'demo');

        try {
            $sembrada = DB::connection('demo')->table('transaction')->exists();
        } catch (Throwable $e) {
            $this->markTestSkipped('No hay conexión a `'.$this->base().'`: '.$e->getMessage());
        }

        if (! $sembrada) {
            $this->markTestSkipped('La base `'.$this->base().'` está vacía: corre `php artisan db:seed --class=DemoSeeder`.');
        }
    }

    /**
     * 🐛 El panel es la primera pantalla de una demostración y **no puede abrir
     * en ceros**.
     *
     * Pasó dos veces por motivos distintos: primero porque los expedientes se
     * repartían parejos hacia atrás y el mes en curso amanecía vacío; después
     * porque faltaba el renglón de tipo de cambio de la MONEDA BASE, sin el cual
     * todo documento en pesos vale NULL y se suma como cero. Con el flete en
     * dólares no se veía; en autotransporte, el panel abría con «facturado
     * $0.00» teniendo cuarenta y cinco facturas.
     */
    public function test_el_panel_no_abre_en_ceros(): void
    {
        $panel = app(DashboardMetrics::class)->all(true);

        $this->assertGreaterThan(0, $panel['embarques'], 'El mes en curso no tiene un solo expediente.');
        $this->assertGreaterThan(0, $panel['facturado'], 'El panel abre con «facturado $0.00».');
        $this->assertGreaterThan(0, $panel['utilidad'], 'El panel abre sin utilidad.');
    }

    protected function admin(): User
    {
        $admin = User::query()->where('username', 'demo.admin')->first();

        $this->assertNotNull($admin, 'Falta la cuenta demo.admin.');

        return $admin;
    }

    /**
     * Abre cada pantalla y exige un 200, diciendo cuál falló y por qué.
     *
     * Se desenvuelve la excepción hasta la causa original: Livewire envuelve la
     * suya en dos capas y el mensaje de arriba no dice nada útil.
     *
     * @param  list<string>  $rutas
     */
    protected function abre(array $rutas): void
    {
        $admin = $this->admin();

        foreach ($rutas as $ruta) {
            try {
                $this->assertSame(200, $this->actingAs($admin)->get($ruta)->status(), "Falló {$ruta}");
            } catch (Throwable $e) {
                $causa = $e;

                while ($causa->getPrevious()) {
                    $causa = $causa->getPrevious();
                }

                $this->fail("Falló {$ruta}: ".$causa::class.' — '.$causa->getMessage());
            }
        }
    }
}
