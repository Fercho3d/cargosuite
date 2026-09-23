<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\PayrollManager;
use App\Models\User;
use App\Support\Ajustes;
use App\Support\Catalogs\CatalogRegistry;
use App\Support\Payroll\Payroll;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Nómina interna.
 *
 * ⚠️ Lo que se comprueba aquí es que **lo que se paga** salga bien: sueldo por
 * los días del periodo, los viajes ya liquidados y los movimientos capturados a
 * mano. No hay IMSS, ni ISR, ni timbrado, y por eso no se prueban: el módulo no
 * los calcula a propósito.
 */
class NominaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        config(['marca.modalidades' => 'terrestre', 'marca.nomina' => true]);

        DB::table('operador')->insert([['operador_id' => 1, 'nombre' => 'Miguel Ramírez', 'activo' => 1]]);

        DB::table('empleado')->insert([
            ['empleado_id' => 1, 'nombre' => 'Miguel Ramírez', 'puesto' => 'Operador',
                'salario_diario' => 400, 'operador_id' => 1, 'activo' => 1, 'clabe' => '012180001234567890'],
            ['empleado_id' => 2, 'nombre' => 'Ana Torres', 'puesto' => 'Tráfico',
                'salario_diario' => 600, 'operador_id' => null, 'activo' => 1, 'clabe' => null],
            ['empleado_id' => 3, 'nombre' => 'Quien ya no está', 'puesto' => null,
                'salario_diario' => 900, 'operador_id' => null, 'activo' => 0, 'clabe' => null],
        ]);
    }

    private function admin(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN): Testable
    {
        return Livewire::actingAs($this->admin($rol))->test(PayrollManager::class);
    }

    private function crear(string $desde = '2026-06-01', string $hasta = '2026-06-15'): Testable
    {
        return $this->pantalla()->set('desde', $desde)->set('hasta', $hasta)->call('crear');
    }

    private function liquidacion(float $importe, string $hasta = '2026-06-15'): int
    {
        $id = DB::table('liquidacion')->insertGetId([
            'numero' => 'LIQ-00001', 'operador_id' => 1, 'desde' => '2026-06-01',
            'hasta' => $hasta, 'estado' => 'pagada',
        ], 'liquidacion_id');

        DB::table('liquidacion_renglon')->insert([
            'liquidacion_id' => $id, 'concepto' => 'Viaje VJ-1', 'tipo' => 'percepcion', 'importe' => $importe,
        ]);

        return $id;
    }

    /**
     * Una quincena del 1 al 15 son quince días, no catorce. El día de menos
     * aparecería en todos los recibos y nadie lo notaría hasta la queja.
     */
    public function test_el_periodo_cuenta_ambos_extremos(): void
    {
        $this->assertSame(15, Payroll::dias('2026-06-01', '2026-06-15'));
    }

    /** Con la nómina apagada (cliente que la lleva en otro sistema) no se entra. */
    public function test_sin_el_modulo_la_nomina_no_existe(): void
    {
        config(['marca.nomina' => false]);

        $this->actingAs($this->admin())->get(route('payments.payroll'))->assertNotFound();
    }

    public function test_la_nomina_llega_con_el_sueldo_de_cada_quien(): void
    {
        $this->crear()->assertHasNoErrors();

        $por = Payroll::porEmpleado(1)->keyBy('empleado_id');

        $this->assertCount(2, $por, 'quien ya no está no entra a la nómina');
        $this->assertSame(6000.0, $por[1]->neto);   // 400 × 15
        $this->assertSame(9000.0, $por[2]->neto);   // 600 × 15
    }

    /** El puente con la flota: lo del viaje entra solo y no se recaptura. */
    public function test_las_liquidaciones_del_operador_entran_a_su_recibo(): void
    {
        $this->liquidacion(3500);

        $this->crear();

        $renglones = DB::table('nomina_renglon')->where('empleado_id', 1)->get();

        $this->assertCount(2, $renglones);
        $this->assertSame(9500.0, Payroll::porEmpleado(1)->firstWhere('empleado_id', 1)->neto);
    }

    /**
     * Lo que sostiene el módulo: una liquidación ya pagada en una nómina NO
     * vuelve a entrar en la siguiente. Sin esto se le paga dos veces el viaje.
     */
    public function test_una_liquidacion_ya_pagada_no_entra_dos_veces(): void
    {
        $this->liquidacion(3500);

        $this->crear();
        $this->crear();

        $this->assertCount(1, DB::table('nomina_renglon')->whereNotNull('liquidacion_id')->get());
    }

    /** Y si la nómina se borra, esa liquidación tiene que volver a estar libre. */
    public function test_borrar_la_nomina_libera_sus_liquidaciones(): void
    {
        $this->liquidacion(3500);

        $this->crear();
        $this->pantalla()->call('borrar', 1);
        $this->crear();

        $this->assertCount(1, DB::table('nomina_renglon')->whereNotNull('liquidacion_id')->get());
        $this->assertSame(0, DB::table('nomina')->where('nomina_id', 1)->count());
    }

    public function test_una_deduccion_resta_del_neto(): void
    {
        $this->crear();

        $this->pantalla()
            ->set('abierta', 1)
            ->set('empleado', '2')->set('concepto', 'Préstamo')->set('tipo', 'deduccion')->set('importe', '1500')
            ->call('agregarRenglon')
            ->assertHasNoErrors();

        $ana = Payroll::porEmpleado(1)->firstWhere('empleado_id', 2);

        $this->assertSame(1500.0, $ana->deducciones);
        $this->assertSame(7500.0, $ana->neto);
    }

    /** La pantalla enseña el desglose, que es lo que se reclama si el neto no cuadra. */
    public function test_el_detalle_ensena_a_cada_quien_con_su_desglose(): void
    {
        $this->liquidacion(3500);
        $this->crear();

        $this->pantalla()->set('abierta', 1)
            ->assertSee('Miguel Ramírez')
            ->assertSee('Ana Torres')
            ->assertDontSee('Quien ya no está')
            ->assertSee('$9,500.00')
            ->assertSee('LIQ-00001');
    }

    /** Una nómina pagada no se toca: el dinero ya salió. */
    public function test_una_nomina_pagada_no_se_puede_modificar(): void
    {
        $this->crear();
        $this->pantalla()->call('pagar', 1);

        $this->pantalla()
            ->set('abierta', 1)
            ->set('empleado', '2')->set('concepto', 'Bono')->set('importe', '100')
            ->call('agregarRenglon')
            ->assertStatus(422);
    }

    /**
     * Apagada, la nómina desaparece entera: si el catálogo de empleados
     * siguiera ahí, se capturaría gente que nadie va a pagar desde aquí.
     */
    public function test_apagada_desaparece_el_catalogo_de_empleados(): void
    {
        $this->assertArrayHasKey('empleados', CatalogRegistry::visibles());

        Ajustes::guardar(['marca.nomina' => false]);

        $this->assertArrayNotHasKey('empleados', CatalogRegistry::visibles());
        $this->assertArrayHasKey('empleados', CatalogRegistry::all(), 'sigue vigilado por el guardián de esquema');
    }

    public function test_solo_los_administradores_entran(): void
    {
        $this->pantalla(User::ROLE_USER)->assertForbidden();
    }

    /** El puente con el sistema fiscal: un renglón por empleado, con su CLABE. */
    public function test_la_exportacion_lleva_el_neto_y_la_clabe(): void
    {
        $this->crear();

        $descarga = $this->pantalla()->call('exportar', 1)->assertFileDownloaded();

        $csv = base64_decode($descarga->effects['download']['content']);

        $this->assertStringContainsString('012180001234567890', $csv);
        $this->assertStringContainsString('6000.00', $csv);
        // Sin separador de miles: con formato, Excel los toma como texto y ya
        // no se pueden sumar.
        $this->assertStringNotContainsString('9,000', $csv);
    }
}
