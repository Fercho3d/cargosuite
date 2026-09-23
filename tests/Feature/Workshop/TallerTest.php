<?php

namespace Tests\Feature\Workshop;

use App\Livewire\Workshop\InventoryManager;
use App\Livewire\Workshop\MaintenanceManager;
use App\Models\User;
use App\Support\Workshop\Inventory;
use App\Support\Workshop\Maintenance;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Taller: mantenimiento de las unidades y almacén de refacciones.
 *
 * Lo que se vigila aquí es lo único que hunde a un almacén: **que la existencia
 * y el kárdex no se separen**. Todo lo demás —quién puede, qué se ve— se puede
 * arreglar después; un inventario que dejó de cuadrar hace tres meses, no.
 */
class TallerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        config(['marca.modalidades' => 'terrestre', 'marca.taller' => true]);

        DB::table('unidad')->insert([
            ['unidad_id' => 1, 'numero' => 'T-101', 'tipo' => 'tractor', 'activo' => 1,
                'kilometraje' => 250000, 'servicio_cada_km' => 20000, 'ultimo_servicio_km' => 231500],
            ['unidad_id' => 2, 'numero' => 'T-102', 'tipo' => 'tractor', 'activo' => 1,
                'kilometraje' => 180000, 'servicio_cada_km' => 20000, 'ultimo_servicio_km' => 175000],
        ]);

        DB::table('refaccion')->insert([
            ['refaccion_id' => 1, 'codigo' => 'FIL-ACE', 'nombre' => 'Filtro de aceite', 'medida' => 'pza',
                'existencia' => 0, 'minimo' => 8, 'costo' => 385, 'activo' => 1],
            ['refaccion_id' => 2, 'codigo' => 'LLA-1122', 'nombre' => 'Llanta 11R22.5', 'medida' => 'pza',
                'existencia' => 0, 'minimo' => 4, 'costo' => 7850, 'activo' => 1],
        ]);

        Inventory::mueve(1, 'entrada', 10, 385, null, [], 1);
        Inventory::mueve(2, 'entrada', 6, 7850, null, [], 1);
    }

    private function admin(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    private function taller(int $rol = User::ROLE_ADMIN): Testable
    {
        return Livewire::actingAs($this->admin($rol))->test(MaintenanceManager::class);
    }

    private function almacen(int $rol = User::ROLE_ADMIN): Testable
    {
        return Livewire::actingAs($this->admin($rol))->test(InventoryManager::class);
    }

    private function orden(string $tipo = 'preventivo', int $odometro = 255000): Testable
    {
        return $this->taller()
            ->set('unidad', '1')->set('tipo', $tipo)->set('entrada', '2026-06-01')
            ->set('odometro', (string) $odometro)->set('descripcion', 'Servicio de 20 000 km')
            ->set('manoObra', '4200')
            ->call('crear');
    }

    /** La existencia SIEMPRE es la suma del kárdex. Sin esto no hay almacén. */
    public function test_la_existencia_y_el_kardex_no_se_separan(): void
    {
        $this->orden();
        $this->taller()->set('abierta', 1)->set('refaccion', '1')->set('cantidad', '2')->call('agregarRefaccion');
        $this->almacen()->call('mover', 2, 'salida')->set('cantidad', '1')->call('guardarMovimiento');
        $this->almacen()->call('mover', 1, 'ajuste')->set('cantidad', '7')->call('guardarMovimiento');

        $this->assertSame([], Inventory::descuadres()->all(), 'La existencia dejó de cuadrar con el kárdex.');
    }

    public function test_poner_una_refaccion_la_descuenta_del_almacen(): void
    {
        $this->orden();

        $this->taller()->set('abierta', 1)
            ->set('refaccion', '1')->set('cantidad', '3')
            ->call('agregarRefaccion')
            ->assertHasNoErrors();

        $this->assertSame(7.0, (float) DB::table('refaccion')->where('refaccion_id', 1)->value('existencia'));
    }

    /** Y quitarla la devuelve: si no, un renglón mal capturado la pierde. */
    public function test_quitarla_la_devuelve_al_almacen(): void
    {
        $this->orden();
        $this->taller()->set('abierta', 1)->set('refaccion', '1')->set('cantidad', '3')->call('agregarRefaccion');

        $renglon = (int) DB::table('mantenimiento_refaccion')->value('renglon_id');
        $this->taller()->set('abierta', 1)->call('quitarRefaccion', $renglon);

        $this->assertSame(10.0, (float) DB::table('refaccion')->where('refaccion_id', 1)->value('existencia'));
        $this->assertSame([], Inventory::descuadres()->all());
    }

    /**
     * El costo se congela al ponerla: la orden de hace un año no puede
     * recalcularse sola con el precio de la compra de esta semana.
     */
    public function test_el_costo_de_la_refaccion_se_congela_en_la_orden(): void
    {
        $this->orden();
        $this->taller()->set('abierta', 1)->set('refaccion', '1')->set('cantidad', '1')->call('agregarRefaccion');

        $this->almacen()->call('mover', 1, 'entrada')->set('cantidad', '5')->set('costo', '900')->call('guardarMovimiento');

        $this->assertSame(385.0, (float) DB::table('mantenimiento_refaccion')->value('costo'));
        $this->assertSame(900.0, (float) DB::table('refaccion')->where('refaccion_id', 1)->value('costo'));
    }

    public function test_el_total_de_la_orden_suma_mano_de_obra_y_refacciones(): void
    {
        $this->orden();
        $this->taller()->set('abierta', 1)->set('refaccion', '1')->set('cantidad', '2')->call('agregarRefaccion');

        $this->assertSame(4970.0, Maintenance::totales(1)['total']);   // 4 200 + 2 × 385
    }

    /** Cerrar un preventivo le pone el reloj a cero a la unidad. */
    public function test_cerrar_el_preventivo_deja_la_unidad_al_dia(): void
    {
        $this->orden('preventivo', 255000);
        $this->taller()->call('cerrar', 1);

        $unidad = DB::table('unidad')->where('unidad_id', 1)->first();

        $this->assertSame(255000, (int) $unidad->ultimo_servicio_km);
        $this->assertSame(255000, (int) $unidad->kilometraje);
        $this->assertSame('cerrado', DB::table('mantenimiento')->where('mantenimiento_id', 1)->value('estado'));
    }

    /** El correctivo no: una llanta no es el servicio de los 20 000 km. */
    public function test_el_correctivo_no_reinicia_el_contador(): void
    {
        $this->orden('correctivo', 255000);
        $this->taller()->call('cerrar', 1);

        $this->assertSame(231500, (int) DB::table('unidad')->where('unidad_id', 1)->value('ultimo_servicio_km'));
    }

    public function test_una_orden_cerrada_no_recibe_refacciones(): void
    {
        $this->orden();
        $this->taller()->call('cerrar', 1);

        $this->taller()->set('abierta', 1)
            ->set('refaccion', '1')->set('cantidad', '1')
            ->call('agregarRefaccion')
            ->assertStatus(422);
    }

    /** El aviso es lo que se usa: qué unidades ya deben servicio. */
    public function test_avisa_de_las_unidades_por_servicio(): void
    {
        $porServicio = Maintenance::porServicio();

        // T-101 lleva 18 500 de 20 000: entró en el último 10 % y avisa.
        // T-102 lleva 5 000 y todavía no molesta a nadie.
        $this->assertSame(['T-101'], $porServicio->pluck('numero')->all());
        $this->assertSame(1500, $porServicio->first()->faltan);
    }

    public function test_el_ajuste_deja_la_existencia_en_lo_contado(): void
    {
        $this->almacen()->call('mover', 1, 'ajuste')->set('cantidad', '4')->call('guardarMovimiento')->assertHasNoErrors();

        $this->assertSame(4.0, (float) DB::table('refaccion')->where('refaccion_id', 1)->value('existencia'));
        $this->assertSame(-6.0, (float) DB::table('movimiento_refaccion')->where('tipo', 'ajuste')->value('cantidad'));
    }

    /** Lo que hace útil el almacén: qué está por acabarse. */
    public function test_lo_que_baja_del_minimo_sale_por_reponer(): void
    {
        $this->assertSame(0, Inventory::bajoMinimo()->count());

        $this->almacen()->call('mover', 1, 'salida')->set('cantidad', '3')->call('guardarMovimiento');

        $this->assertSame(['Filtro de aceite'], Inventory::bajoMinimo()->pluck('nombre')->all());
    }

    public function test_el_valor_del_almacen_es_existencia_por_costo(): void
    {
        $this->assertSame(10 * 385.0 + 6 * 7850.0, Inventory::valor());
    }

    public function test_solo_los_administradores_entran(): void
    {
        $this->taller(User::ROLE_USER)->assertForbidden();
        $this->almacen(User::ROLE_USER)->assertForbidden();
    }
}
