<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\PayrollDetail;
use App\Livewire\Payments\PayrollManager;
use App\Models\User;
use App\Support\Cfdi\PacClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\Support\FakePacClient;
use Tests\TestCase;

/**
 * Timbrado del CFDI de nómina (4.0 + Nómina 1.2).
 *
 * Nada sale a la red: el PAC es `FakePacClient`, que guarda el layout que se le
 * mandó para poder afirmar sobre él.
 */
class NominaTimbradoTest extends TestCase
{
    private FakePacClient $pac;

    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        Storage::fake('documentos');
        config(['marca.nomina' => true, 'timbrado.habilitado' => true]);

        $this->pac = new FakePacClient;
        $this->app->instance(PacClient::class, $this->pac);

        DB::table('company')->insert([
            'company_id' => 1, 'name' => 'Transportes', 'business_name' => 'TRANSPORTES DEMO', 'rfc' => 'TDE010101AB1',
            'regimen_fiscal' => '601', 'postal_code' => '20000', 'registro_patronal' => 'B5510768108', 'riesgo_puesto' => '4',
        ]);
    }

    private function empleado(string $regimen = 'sueldos', array $mas = []): int
    {
        return DB::table('empleado')->insertGetId($mas + [
            'nombre' => 'Ana Torres', 'salario_diario' => 600, 'regimen' => $regimen, 'ingreso' => '2025-01-01',
            'activo' => 1, 'rfc' => 'TOAA800101AB1', 'curp' => 'TOAA800101MASRRN09', 'nss' => '12345678901',
            'codigo_postal' => '20010', 'entidad' => 'AGU',
        ], 'empleado_id');
    }

    /** Crea la nómina de la quincena y abre su página, sin pagar todavía. */
    private function abierta(): Testable
    {
        $admin = User::forceCreate([
            'username' => 'jefa', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);

        Livewire::actingAs($admin)->test(PayrollManager::class)
            ->set('desde', '2026-06-01')->set('hasta', '2026-06-15')->set('periodicidad', 'quincenal')
            ->call('crear');

        return Livewire::test(PayrollDetail::class, ['nomina' => $this->nomina()]);
    }

    /** Ídem, con la nómina entera pagada. */
    private function pagada(): Testable
    {
        return $this->abierta()->call('pagar');
    }

    private function nomina(): int
    {
        return (int) DB::table('nomina')->value('nomina_id');
    }

    public function test_timbra_el_recibo_de_sueldos_con_el_complemento_de_nomina(): void
    {
        $this->empleado();

        $this->pagada()->set('emisor', '1')->call('timbrar')->assertHasNoErrors();

        $this->assertSame('timbrado', DB::table('nomina_recibo')->value('estado'));
        $this->assertStringContainsString("[ComplementoNomina]\nVersion=1.2", $this->pac->layoutRecibido);
        $this->assertStringContainsString("TipoRegimen=02\n", $this->pac->layoutRecibido);
        $this->assertStringContainsString("RegistroPatronal=B5510768108\n", $this->pac->layoutRecibido);
        $this->assertStringContainsString("TipoPercepcion=001\n", $this->pac->layoutRecibido);
        $this->assertStringContainsString("TipoDeduccion=002\n", $this->pac->layoutRecibido);
        $this->assertStringContainsString("PeriodicidadPago=04\n", $this->pac->layoutRecibido);
        $this->assertStringContainsString("NumDiasPagados=15\n", $this->pac->layoutRecibido);
        Storage::disk('documentos')->assertExists('nomina/NOM-00001/'.$this->pac->uuid.'.xml');
    }

    /** Total = percepciones − deducciones, con los mismos centavos que el recibo. */
    public function test_el_total_del_cfdi_es_el_neto_de_la_nomina(): void
    {
        $id = $this->empleado();
        $this->pagada()->set('emisor', '1')->call('timbrar');

        $neto = DB::table('nomina_renglon')->where('empleado_id', $id)->get()
            ->sum(fn ($r) => ['percepcion' => 1, 'deduccion' => -1, 'patronal' => 0][$r->tipo] * round((float) $r->importe, 2));

        $this->assertStringContainsString('total='.number_format($neto, 2, '.', '')."\n", $this->pac->layoutRecibido);
    }

    public function test_asimilados_van_sin_registro_patronal_y_con_su_propia_clave(): void
    {
        $this->empleado('asimilados');

        $this->pagada()->set('emisor', '1')->call('timbrar');

        $this->assertStringContainsString("TipoRegimen=09\n", $this->pac->layoutRecibido);
        $this->assertStringContainsString("TipoPercepcion=046\n", $this->pac->layoutRecibido);
        $this->assertStringNotContainsString('RegistroPatronal', $this->pac->layoutRecibido);
    }

    public function test_una_nomina_abierta_no_se_timbra(): void
    {
        $this->empleado();
        $this->pagada()->call('reabrir');

        $this->assertNull($this->pac->layoutRecibido);
    }

    /** Uno sin CURP no detiene a los demás, y su error queda a la vista. */
    public function test_un_empleado_con_datos_incompletos_no_frena_a_los_demas(): void
    {
        $this->empleado();
        $this->empleado(mas: ['nombre' => 'Sin Curp', 'curp' => null]);

        $this->pagada()->set('emisor', '1')->call('timbrar');

        $this->assertSame(['error', 'timbrado'], DB::table('nomina_recibo')->orderBy('estado')->pluck('estado')->all());
    }

    /** Honorarios y «ninguno» no son nómina: no se timbran. */
    public function test_honorarios_no_se_timbran(): void
    {
        $this->empleado('honorarios');

        $this->pagada()->set('emisor', '1')->call('timbrar');

        $this->assertNull($this->pac->layoutRecibido);
    }

    /** Timbrar dos veces no duplica recibos ante el SAT. */
    public function test_no_se_timbra_dos_veces_el_mismo_recibo(): void
    {
        $this->empleado();
        $pantalla = $this->pagada()->set('emisor', '1')->call('timbrar');
        $this->pac->layoutRecibido = null;

        $pantalla->call('timbrar');

        $this->assertNull($this->pac->layoutRecibido);
    }

    public function test_no_se_reabre_con_recibos_vigentes(): void
    {
        $this->empleado();
        $super = User::forceCreate([
            'username' => 'super', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_SUPER_ADMIN, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
        $this->pagada()->set('emisor', '1')->call('timbrar');

        Livewire::actingAs($super)->test(PayrollDetail::class, ['nomina' => $this->nomina()])->call('reabrir')->assertStatus(422);
    }

    public function test_cancelar_libera_el_recibo_para_volver_a_timbrar(): void
    {
        $this->empleado();
        $pantalla = $this->pagada()->set('emisor', '1')->call('timbrar');

        $pantalla->call('cancelarRecibo', (int) DB::table('nomina_recibo')->value('recibo_id'));

        $this->assertSame([['uuid' => $this->pac->uuid, 'rfcEmisor' => 'TDE010101AB1', 'motivo' => '02', 'sustituye' => null]], $this->pac->cancelaciones);
    }

    /** Pagar a uno solo deja timbrar su recibo sin esperar a los demás. */
    public function test_un_empleado_pagado_por_separado_se_timbra_solo(): void
    {
        $ana = $this->empleado();
        $this->empleado(mas: ['nombre' => 'Luis Pérez']);

        $this->abierta()->set('emisor', '1')->call('pagarEmpleado', $ana)->call('timbrar', $ana)->assertHasNoErrors();

        $this->assertSame([$ana => 'timbrado'], DB::table('nomina_recibo')->whereNotNull('uuid')->pluck('estado', 'empleado_id')->all());
    }

    public function test_lo_que_no_se_ha_pagado_no_se_timbra(): void
    {
        $ana = $this->empleado();

        $this->abierta()->set('emisor', '1')->call('timbrar', $ana)->assertHasErrors('timbrado');

        $this->assertNull($this->pac->layoutRecibido);
    }

    /** A quien ya se le pagó no se le agrega nada: su dinero ya salió. */
    public function test_al_empleado_pagado_no_se_le_puede_agregar_nada(): void
    {
        $ana = $this->empleado();

        $this->abierta()->call('pagarEmpleado', $ana)
            ->set('empleado', (string) $ana)->set('concepto', 'Bono')->set('importe', '100')
            ->call('agregarRenglon')
            ->assertStatus(422);
    }

    /** Pagados todos uno por uno, la nómina queda pagada sola. */
    public function test_pagar_a_todos_por_separado_cierra_la_nomina(): void
    {
        $ana = $this->empleado();
        $luis = $this->empleado(mas: ['nombre' => 'Luis Pérez']);

        $this->abierta()->call('pagarEmpleado', $ana)->call('pagarEmpleado', $luis);

        $this->assertSame('pagada', DB::table('nomina')->value('estado'));
    }

    /** Un ISR recalculado por otro empleado no mueve el recibo de quien ya cobró. */
    public function test_recalcular_no_toca_al_empleado_pagado(): void
    {
        $ana = $this->empleado();
        $luis = $this->empleado(mas: ['nombre' => 'Luis Pérez']);
        $pantalla = $this->abierta()->call('pagarEmpleado', $ana);
        $antes = DB::table('nomina_renglon')->where('empleado_id', $ana)->orderBy('renglon_id')->get()->toArray();

        $pantalla->set('empleado', (string) $luis)->set('concepto', 'Bono')->set('importe', '100')->call('agregarRenglon');

        $this->assertEquals($antes, DB::table('nomina_renglon')->where('empleado_id', $ana)->orderBy('renglon_id')->get()->toArray());
    }
}
