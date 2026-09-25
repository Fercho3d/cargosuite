<?php

namespace Tests\Feature\Rutas;

use App\Livewire\Rutas\RouteDetail;
use App\Livewire\Rutas\RouteList;
use App\Models\User;
use App\Support\Rutas\Tarifario;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Configuración de rutas: tarifa, costos, subcontrato y diésel, con historial.
 *
 * Los importes esperados salen a mano: 900 km a 2.25 km/L son 400 L.
 */
class RutasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        config(['marca.modalidades' => 'terrestre']);

        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'Monterrey', 'deleted' => 0]);
        DB::table('dicharge_port')->insert(['dicharge_port_id' => 1, 'name' => 'Ciudad de México', 'deleted' => 0]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cementos'], ['client_id' => 2, 'fullName' => 'Aceros']]);
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Fletes Uno'], ['provider_id' => 2, 'fullName' => 'Fletes Dos']]);
        DB::table('ruta')->insert(['ruta_id' => 1, 'origen_id' => 1, 'destino_id' => 1, 'km' => 900, 'rendimiento' => 2.25, 'activo' => 1]);
    }

    private function admin(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    private function tarifa(string $tipo, string $concepto, float $precio, string $desde, ?int $cliente = null, ?int $proveedor = null): void
    {
        DB::table('tarifa_ruta')->insert([
            'ruta_id' => 1, 'tipo' => $tipo, 'concepto' => $concepto, 'client_id' => $cliente,
            'provider_id' => $proveedor, 'precio' => $precio, 'vigente_desde' => $desde,
        ]);
    }

    private function resumen(?string $fecha = null, ?int $cliente = null): array
    {
        return Tarifario::resumen(DB::table('ruta')->first(), $fecha, $cliente);
    }

    public function test_el_diesel_es_el_del_dia_o_el_ultimo_antes(): void
    {
        DB::table('precio_diesel')->insert([['fecha' => '2026-09-01', 'precio' => 25.00], ['fecha' => '2026-09-10', 'precio' => 26.00]]);

        $this->assertSame([25.0, 26.0, null], [
            (float) Tarifario::diesel('2026-09-09')->precio,
            (float) Tarifario::diesel('2026-09-15')->precio,
            Tarifario::diesel('2026-08-31'),
        ]);
    }

    /** 400 L al precio de cada fecha: el viaje viejo no se recalcula con el diésel de hoy. */
    public function test_el_costo_del_diesel_sale_del_precio_de_su_fecha(): void
    {
        DB::table('precio_diesel')->insert([['fecha' => '2026-09-01', 'precio' => 25.00], ['fecha' => '2026-09-10', 'precio' => 26.00]]);

        $this->assertSame([10000.0, 10400.0], [$this->resumen('2026-09-05')['diesel'], $this->resumen('2026-09-12')['diesel']]);
    }

    /** Un precio nuevo no borra el anterior: cada fecha ve el suyo. */
    public function test_la_tarifa_guarda_historial(): void
    {
        $this->tarifa('venta', 'Flete', 24000, '2026-01-01');
        $this->tarifa('venta', 'Flete', 25200, '2026-08-01');

        $this->assertSame([24000.0, 25200.0], [$this->resumen('2026-07-31')['venta'], $this->resumen('2026-08-01')['venta']]);
    }

    /** La tarifa especial sustituye la general de su concepto; los demás conceptos siguen. */
    public function test_el_cliente_con_tarifa_especial_paga_la_suya(): void
    {
        $this->tarifa('venta', 'Flete', 25000, '2026-01-01');
        $this->tarifa('venta', 'Maniobras', 2000, '2026-01-01');
        $this->tarifa('venta', 'Flete', 23000, '2026-01-01', cliente: 1);

        $this->assertSame([27000.0, 25000.0, 27000.0], [
            $this->resumen('2026-09-01')['venta'],
            $this->resumen('2026-09-01', 1)['venta'],
            $this->resumen('2026-09-01', 2)['venta'],
        ]);
    }

    /** Margen propio = tarifa − (diésel + costos); subcontratado, con el externo más barato. */
    public function test_el_margen_con_unidad_propia_y_subcontratado(): void
    {
        DB::table('precio_diesel')->insert(['fecha' => '2026-09-01', 'precio' => 25.00]);
        $this->tarifa('venta', 'Flete', 25000, '2026-01-01');
        $this->tarifa('costo', 'Casetas', 2500, '2026-01-01');
        $this->tarifa('subcontrato', 'Flete subcontratado', 22000, '2026-01-01', proveedor: 1);
        $this->tarifa('subcontrato', 'Flete subcontratado', 21000, '2026-01-01', proveedor: 2);

        $resumen = $this->resumen('2026-09-02');

        $this->assertSame([12500.0, 50.0, 21000.0, 16.0], [
            $resumen['propio'], $resumen['margenPropio'], $resumen['subcontrato'], $resumen['margenSubcontrato'],
        ]);
    }

    public function test_capturar_un_precio_nuevo_deja_el_anterior_en_el_historial(): void
    {
        $this->tarifa('venta', 'Flete', 24000, '2026-01-01');

        Livewire::actingAs($this->admin())->test(RouteDetail::class, ['ruta' => 1])
            ->set('tipo', 'venta')->set('concepto', 'Flete')->set('precio', '25500')->set('vigenteDesde', '2026-09-01')
            ->call('agregarPrecio')->assertHasNoErrors();

        $this->assertSame([24000.0, 25500.0], DB::table('tarifa_ruta')->orderBy('vigente_desde')->pluck('precio')->map(fn ($p) => (float) $p)->all());
    }

    /** Capturar dos veces el mismo día corrige ese día, no lo duplica. */
    public function test_el_diesel_se_captura_una_vez_por_dia(): void
    {
        $pantalla = Livewire::actingAs($this->admin())->test(RouteList::class);

        $pantalla->set('dieselPrecio', '25.40')->call('guardarDiesel');
        $pantalla->set('dieselPrecio', '25.45')->call('guardarDiesel');

        $this->assertSame([25.45], DB::table('precio_diesel')->pluck('precio')->map(fn ($p) => (float) $p)->all());
    }

    public function test_una_ruta_no_se_da_de_alta_dos_veces(): void
    {
        Livewire::actingAs($this->admin())->test(RouteList::class)
            ->set('origen', '1')->set('destino', '1')->set('km', '900')
            ->call('crear')->assertHasErrors('destino');
    }

    public function test_las_pantallas_abren_para_el_administrador(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('rutas.index'))->assertOk()->assertSee('Monterrey → Ciudad de México');
        $this->get(route('rutas.show', 1))->assertOk()->assertSee(__('Venta al cliente'));
    }

    public function test_quien_no_es_administrador_no_entra(): void
    {
        $this->actingAs($this->admin(User::ROLE_USER))->get(route('rutas.index'))->assertForbidden();
    }
}
