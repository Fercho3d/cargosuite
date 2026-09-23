<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\PayrollManager;
use App\Models\User;
use App\Support\Catalogs\CatalogRegistry;
use App\Support\Payroll\Payroll;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Impuestos y cuotas de la nómina, con las tablas de 2026 que precarga la
 * migración.
 *
 * Los importes esperados se sacaron a mano, fuera del código: quincena del 1
 * al 15 de junio de 2026 (15 días), UMA 117.31, tarifa mensual del art. 96
 * proporcional por días (base / días × 30.4), y para el IMSS un empleado con
 * un año cumplido (14 días de vacaciones: factor 1 + 18.5 / 365).
 */
class NominaImpuestosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        config(['marca.nomina' => true]);
    }

    private function empleado(float $salario, string $regimen = 'sueldos', array $mas = []): int
    {
        return DB::table('empleado')->insertGetId($mas + [
            'nombre' => 'Ana Torres', 'salario_diario' => $salario, 'regimen' => $regimen,
            'ingreso' => '2025-01-01', 'activo' => 1,
        ], 'empleado_id');
    }

    private function pantalla(): Testable
    {
        $admin = User::forceCreate([
            'username' => 'jefa', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);

        return Livewire::actingAs($admin)->test(PayrollManager::class)
            ->set('desde', '2026-06-01')->set('hasta', '2026-06-15')->set('periodicidad', 'quincenal')
            ->call('crear');
    }

    private function importe(string $concepto): ?float
    {
        $valor = DB::table('nomina_renglon')->where('concepto', 'like', $concepto.'%')->value('importe');

        return $valor === null ? null : round((float) $valor, 2);
    }

    public function test_sueldos_retiene_isr_con_la_tarifa_del_periodo(): void
    {
        $this->empleado(600);
        $this->pantalla();

        $this->assertSame(1099.38, $this->importe('ISR'));
    }

    public function test_sueldos_descuenta_las_cuotas_obreras_del_imss(): void
    {
        $this->empleado(600);
        $this->pantalla();

        $this->assertSame(241.29, $this->importe('IMSS obrero'));
    }

    public function test_sueldos_calcula_el_imss_patronal(): void
    {
        $this->empleado(600);
        $this->pantalla();

        $this->assertSame(881.84, $this->importe('IMSS patronal'));
    }

    public function test_sueldos_calcula_retiro_cesantia_y_vejez_patronal(): void
    {
        $this->empleado(600);
        $this->pantalla();

        $this->assertSame(899.56, $this->importe('Retiro, cesantía y vejez'));
    }

    public function test_sueldos_calcula_la_aportacion_al_infonavit(): void
    {
        $this->empleado(600);
        $this->pantalla();

        $this->assertSame(472.81, $this->importe('INFONAVIT 5'));
    }

    /** Lo patronal es costo de la empresa: se enseña, pero no baja el neto. */
    public function test_lo_patronal_no_resta_del_neto(): void
    {
        $this->empleado(600);
        $this->pantalla();

        $this->assertSame(round(9000 - 1099.38 - 241.29, 2), Payroll::porEmpleado(1)->first()->neto);
    }

    public function test_el_subsidio_al_empleo_baja_el_isr_de_quien_gana_poco(): void
    {
        $this->empleado(330);
        $this->pantalla();

        $this->assertSame(139.27, $this->importe('ISR'));
    }

    /** Art. 36 LSS: a quien gana el mínimo sus cuotas obreras las paga el patrón. */
    public function test_con_salario_minimo_no_se_le_descuenta_imss(): void
    {
        $this->empleado(315.04);
        $this->pantalla();

        $this->assertNull($this->importe('IMSS obrero'));
    }

    public function test_asimilados_retiene_isr_sin_subsidio(): void
    {
        $this->empleado(330, 'asimilados');
        $this->pantalla();

        $this->assertSame(382.10, $this->importe('ISR'));
    }

    public function test_asimilados_no_paga_imss(): void
    {
        $this->empleado(330, 'asimilados');
        $this->pantalla();

        $this->assertNull($this->importe('IMSS'));
    }

    /** 10 000 + IVA 1 600 − ISR 1 000 − IVA retenido 1 066.67. */
    public function test_honorarios_suma_iva_y_retiene_isr_e_iva(): void
    {
        $this->empleado(10000 / 15, 'honorarios');
        $this->pantalla();

        $this->assertSame(9533.33, Payroll::porEmpleado(1)->first()->neto);
    }

    public function test_sin_regimen_no_se_calcula_nada(): void
    {
        $this->empleado(600, 'ninguno');
        $this->pantalla();

        $this->assertSame(0, DB::table('nomina_renglon')->where('automatico', 1)->count());
    }

    public function test_el_credito_infonavit_se_descuenta(): void
    {
        $this->empleado(600, 'sueldos', ['infonavit_descuento' => 850]);
        $this->pantalla();

        $this->assertSame(850.0, $this->importe('Crédito INFONAVIT'));
    }

    /** Un bono capturado después cambia la base: el ISR se rehace solo. */
    public function test_un_bono_capturado_rehace_el_isr(): void
    {
        $id = $this->empleado(600);

        $this->pantalla()->set('empleado', (string) $id)->set('concepto', 'Bono')
            ->set('tipo', 'percepcion')->set('importe', '1000')->call('agregarRenglon');

        $this->assertSame(1, DB::table('nomina_renglon')->where('concepto', 'like', 'ISR%')->count());
        $this->assertGreaterThan(1099.38, $this->importe('ISR'));
    }

    public function test_un_empleado_semanal_no_entra_a_la_quincena(): void
    {
        $this->empleado(600, 'sueldos', ['periodicidad' => 'semanal']);
        $this->pantalla();

        $this->assertSame(0, DB::table('nomina_renglon')->count());
    }

    public function test_las_tablas_fiscales_se_editan_en_catalogos(): void
    {
        $this->assertArrayHasKey('tablas-fiscales', CatalogRegistry::visibles());
    }
}
