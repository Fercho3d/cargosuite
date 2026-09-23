<?php

namespace Tests\Feature\Services;

use App\Livewire\Services\ServiceForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * El contrato en PDF de un servicio.
 *
 * Se guarda en `services/{id}/pdf/` del disco compartido y la columna
 * `service.contract` lleva solo el nombre, igual que lo dejó Yii2 para los
 * 156 contratos históricos.
 */
class ServiceContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        Storage::fake('documentos');

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1]]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('charge_type')->insert([[
            'charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0.16,
            'tax_retention' => 0, 'non_deductible' => 0, 'deleted' => 0,
        ]]);
    }

    private function admin(): User
    {
        return User::forceCreate(['username' => 'admin', 'password' => 'secreto-de-prueba', 'role' => User::ROLE_SUPER_ADMIN, 'status' => 1]);
    }

    private function formulario(?int $service = null): Testable
    {
        $this->actingAs($this->admin());

        return Livewire::test(ServiceForm::class, ['service' => $service])
            ->set('form.description', 'Flete Manzanillo')
            ->set('form.price', '850')
            ->set('form.charge_type_id', '1')
            ->set('form.account_id', '1')
            ->set('form.party_id', '1');
    }

    public function test_al_dar_de_alta_se_guarda_el_pdf_en_la_carpeta_del_servicio(): void
    {
        $this->formulario()
            ->set('contract', UploadedFile::fake()->create('MANZANILLO-YOKOHAMA.pdf', 120, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $servicio = DB::table('service')->first();

        $this->assertSame('MANZANILLO-YOKOHAMA.pdf', $servicio->contract);
        Storage::disk('documentos')->assertExists("services/{$servicio->service_id}/pdf/MANZANILLO-YOKOHAMA.pdf");
    }

    public function test_solo_se_acepta_pdf_de_hasta_cinco_megas(): void
    {
        $this->formulario()
            ->set('contract', UploadedFile::fake()->create('contrato.docx', 10))
            ->call('save')
            ->assertHasErrors(['contract' => 'mimes']);

        $this->formulario()
            ->set('contract', UploadedFile::fake()->create('contrato.pdf', 6000, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['contract' => 'max']);

        $this->assertSame(0, DB::table('service')->count());
    }

    public function test_editar_sin_subir_nada_conserva_el_contrato_que_ya_tenia(): void
    {
        DB::table('service')->insert([
            'service_id' => 7, 'description' => 'Flete', 'price' => 850, 'charge_type_id' => 1,
            'client_id' => 1, 'type' => 1, 'active' => 1, 'account_id' => 1, 'contract' => 'VIEJO.pdf',
        ]);

        $this->formulario(7)
            ->assertSet('contractName', 'VIEJO.pdf')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('VIEJO.pdf', DB::table('service')->where('service_id', 7)->value('contract'));
    }

    public function test_la_ruta_sirve_el_pdf_en_linea(): void
    {
        DB::table('service')->insert([
            'service_id' => 7, 'description' => 'Flete', 'price' => 850, 'charge_type_id' => 1,
            'client_id' => 1, 'type' => 1, 'active' => 1, 'contract' => 'ALTAMIRA-ROTTERDAM.pdf',
        ]);
        Storage::disk('documentos')->put('services/7/pdf/ALTAMIRA-ROTTERDAM.pdf', '%PDF-1.4 prueba');

        $this->actingAs($this->admin())
            ->get('/terceros/servicios/7/contrato')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="ALTAMIRA-ROTTERDAM.pdf"');
    }

    /** En los datos históricos hay archivos `.PDF` cuya columna dice `.pdf`; el original reintentaba igual. */
    public function test_la_ruta_reintenta_con_la_extension_en_mayusculas(): void
    {
        DB::table('service')->insert([
            'service_id' => 7, 'description' => 'Flete', 'price' => 850, 'charge_type_id' => 1,
            'client_id' => 1, 'type' => 1, 'active' => 1, 'contract' => 'contrato.pdf',
        ]);
        Storage::disk('documentos')->put('services/7/pdf/contrato.PDF', '%PDF-1.4 prueba');

        $this->actingAs($this->admin())->get('/terceros/servicios/7/contrato')->assertOk();
    }

    public function test_sin_contrato_o_sin_archivo_responde_404(): void
    {
        DB::table('service')->insert([
            'service_id' => 7, 'description' => 'Flete', 'price' => 850, 'charge_type_id' => 1,
            'client_id' => 1, 'type' => 1, 'active' => 1, 'contract' => 'perdido.pdf',
        ]);

        $this->actingAs($this->admin())->get('/terceros/servicios/7/contrato')->assertNotFound();
    }
}
