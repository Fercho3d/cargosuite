<?php

namespace Tests\Feature\Catalogs;

use App\Livewire\Catalogs\CatalogManager;
use App\Models\User;
use App\Support\Catalogs\CatalogRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CatalogSchema;
use Tests\TestCase;

/**
 * Alta, baja y edición de catálogos.
 *
 * Corre sobre tablas levantadas a partir de las propias definiciones, no contra
 * la copia local de `frego`: estas pruebas escriben, y esa base la usan las
 * pruebas de paridad.
 */
class CatalogManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Uno con baja lógica y otro sin ella, para cubrir las dos formas de borrar.
        CatalogSchema::create(CatalogRegistry::find('companias'));
        CatalogSchema::create(CatalogRegistry::find('puertos-carga'));
        CatalogSchema::create(CatalogRegistry::find('buques'));
        CatalogSchema::create(CatalogRegistry::find('navieras'));
        CatalogSchema::create(CatalogRegistry::find('modalidades'));
        CatalogSchema::create(CatalogRegistry::find('bancos'));
        CatalogSchema::create(CatalogRegistry::find('tipos-cargo'));
        CatalogSchema::create(CatalogRegistry::find('codigos-impuesto'));
    }

    /** Super administrador: las compañías y los tipos de cargo son suyos, como en Yii2. */
    private function usuario(int $rol = User::ROLE_SUPER_ADMIN): User
    {
        $usuario = new User;
        $usuario->usr_id = 7;
        $usuario->role = $rol;

        return $usuario;
    }

    private function pantalla(string $slug, int $rol = User::ROLE_SUPER_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(CatalogManager::class, ['catalog' => $slug]);
    }

    public function test_un_catalogo_inexistente_responde_404(): void
    {
        $this->actingAs($this->usuario());

        // Livewire convierte el 404 del `mount()` en respuesta en vez de dejar
        // salir la excepción, así que se comprueba sobre la respuesta.
        Livewire::test(CatalogManager::class, ['catalog' => 'no-existe'])->assertNotFound();
    }

    public function test_agregar_un_registro(): void
    {
        $this->pantalla('companias')
            ->call('create')
            ->set('form.name', 'FTM')
            ->set('form.business_name', 'Frego Transportaciones Marítimas')
            ->set('form.rfc', 'FTM010101AAA')
            ->set('form.active', true)
            ->call('save')
            ->assertHasNoErrors();

        $fila = DB::table('company')->first();

        $this->assertSame('FTM', $fila->name);
        $this->assertSame(1, (int) $fila->active);
    }

    public function test_los_campos_obligatorios_se_validan(): void
    {
        $this->pantalla('companias')
            ->call('create')
            ->set('form.name', '')
            ->call('save')
            ->assertHasErrors('form.name');

        $this->assertSame(0, DB::table('company')->count());
    }

    public function test_editar_un_registro(): void
    {
        DB::table('company')->insert(['company_id' => 1, 'name' => 'FTA', 'active' => 1]);

        $this->pantalla('companias')
            ->call('edit', 1)
            ->assertSet('form.name', 'FTA')
            ->set('form.name', 'FTA renombrada')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('FTA renombrada', DB::table('company')->where('company_id', 1)->value('name'));
        $this->assertSame(1, DB::table('company')->count(), 'Editar no debe crear un registro nuevo.');
    }

    /** Un catálogo que se referencia desde la operación no se borra: se da de baja. */
    public function test_el_catalogo_con_baja_logica_no_borra_la_fila(): void
    {
        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'Manzanillo', 'deleted' => 0]);

        $pantalla = $this->pantalla('puertos-carga')->call('delete', 1);

        $this->assertSame(1, (int) DB::table('loading_ports')->where('port_id', 1)->value('deleted'));
        $this->assertCount(0, $pantalla->viewData('filas')->items(), 'Lo dado de baja no debe seguir en la lista.');
    }

    public function test_el_catalogo_sin_baja_logica_si_borra(): void
    {
        DB::table('vessel')->insert(['vessel_id' => 1, 'vessel_name' => 'Ever Given']);

        $this->pantalla('buques')->call('delete', 1);

        $this->assertSame(0, DB::table('vessel')->count());
    }

    public function test_la_busqueda_filtra(): void
    {
        DB::table('vessel')->insert([
            ['vessel_id' => 1, 'vessel_name' => 'Ever Given'],
            ['vessel_id' => 2, 'vessel_name' => 'Maersk Alabama'],
        ]);

        $filas = $this->pantalla('buques')->set('search', 'maersk')->viewData('filas');

        $this->assertCount(1, $filas->items());
        $this->assertSame('Maersk Alabama', $filas->items()[0]->vessel_name);
    }

    public function test_quien_no_es_administrador_no_puede_escribir(): void
    {
        DB::table('vessel')->insert(['vessel_id' => 1, 'vessel_name' => 'Ever Given']);

        $this->pantalla('buques', User::ROLE_USER)->call('delete', 1)->assertForbidden();
        $this->pantalla('buques', User::ROLE_USER)->call('create')->assertForbidden();

        $this->assertSame(1, DB::table('vessel')->count());
    }

    /** El original la daba de alta con `active = 1`; inactiva no sale en ningún selector. */
    public function test_una_compania_nueva_nace_activa_y_con_regimen_por_omision(): void
    {
        $this->pantalla('companias')
            ->call('create')
            ->assertSet('form.active', true)
            ->assertSet('form.regimen_fiscal', '601')
            ->set('form.name', 'FTM')
            ->set('form.regimen_fiscal', '')
            ->call('save')
            ->assertHasNoErrors();

        $fila = DB::table('company')->first();

        $this->assertSame(1, (int) $fila->active);
        // Vacío no se escribe como NULL: se deja que la columna aplique su default.
        $this->assertSame('601', $fila->regimen_fiscal);
    }

    public function test_un_banco_nuevo_nace_activo(): void
    {
        $this->pantalla('bancos')
            ->call('create')
            ->set('form.bank_name', 'BBVA')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, (int) DB::table('bank')->value('active'));
    }

    /** `carrier.email` y `carrier.password` son `NOT NULL`: sin ellos el insert reventaba. */
    public function test_una_naviera_exige_correo_y_nace_con_contrasena_aleatoria(): void
    {
        $this->pantalla('navieras')
            ->call('create')
            ->set('form.name', 'Maersk')
            ->call('save')
            ->assertHasErrors(['form.email' => 'required']);

        $this->pantalla('navieras')
            ->call('create')
            ->set('form.name', 'Maersk')
            ->set('form.email', 'ops@maersk.com')
            ->call('save')
            ->assertHasNoErrors();

        $fila = DB::table('carrier')->first();

        $this->assertSame('ops@maersk.com', $fila->email);
        $this->assertNotSame('', (string) $fila->password);
    }

    public function test_el_correo_de_la_naviera_no_se_repite_pero_se_puede_conservar_al_editar(): void
    {
        DB::table('carrier')->insert(['carrier_id' => 1, 'name' => 'Maersk', 'email' => 'ops@maersk.com', 'password' => 'x']);

        $this->pantalla('navieras')
            ->call('create')
            ->set('form.name', 'Otra')
            ->set('form.email', 'ops@maersk.com')
            ->call('save')
            ->assertHasErrors(['form.email' => 'unique']);

        $this->pantalla('navieras')
            ->call('edit', 1)
            ->set('form.name', 'Maersk Line')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Maersk Line', DB::table('carrier')->where('carrier_id', 1)->value('name'));
    }

    /**
     * `booking_continuity.modality` es `ON DELETE CASCADE`: borrar la modalidad
     * borraría la continuidad de todos sus bookings sin que la base se quejara.
     */
    public function test_un_catalogo_en_uso_no_se_borra_aunque_la_foranea_lo_permita(): void
    {
        Schema::create('booking_continuity', function ($table) {
            $table->increments('continuity_id');
            $table->integer('modality')->nullable();
        });
        DB::table('modality')->insert(['modality_id' => 1, 'modality_name' => 'FCL']);
        DB::table('booking_continuity')->insert([['modality' => 1], ['modality' => 1]]);

        $this->pantalla('modalidades')
            ->call('delete', 1)
            ->assertHasErrors('delete')
            ->assertSee('lo usan 2 registros de booking_continuity');

        $this->assertSame(1, DB::table('modality')->count());
    }

    public function test_un_catalogo_sin_uso_si_se_borra(): void
    {
        Schema::create('booking_continuity', function ($table) {
            $table->increments('continuity_id');
            $table->integer('modality')->nullable();
        });
        DB::table('modality')->insert(['modality_id' => 1, 'modality_name' => 'FCL']);

        $this->pantalla('modalidades')->call('delete', 1)->assertHasNoErrors();

        $this->assertSame(0, DB::table('modality')->count());
    }

    public function test_un_error_de_la_base_al_guardar_se_avisa_en_pantalla(): void
    {
        // Lo que pase la validación pero la base rechace (aquí, una razón social
        // repetida con índice único que la pantalla no valida) se avisa en
        // pantalla en vez de reventar.
        Schema::table('company', fn ($table) => $table->unique('business_name'));
        DB::table('company')->insert(['company_id' => 1, 'name' => 'FTM', 'business_name' => 'FTM SA', 'active' => 1]);

        $this->pantalla('companias')
            ->call('create')
            ->set('form.name', 'Otra')
            ->set('form.business_name', 'FTM SA')
            ->call('save')
            ->assertHasErrors('form')
            ->assertSet('editing', 0);

        $this->assertSame(1, DB::table('company')->count());
    }

    public function test_no_se_edita_un_renglon_dado_de_baja_aunque_se_mande_su_id(): void
    {
        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'BUSAN', 'deleted' => 1]);

        $this->pantalla('puertos-carga')->call('edit', 1)->assertStatus(404);
    }

    public function test_tampoco_se_guarda_sobre_un_renglon_dado_de_baja(): void
    {
        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'BUSAN', 'deleted' => 1]);

        $this->pantalla('puertos-carga')
            ->call('create')
            ->set('editing', 1)
            ->set('form.port_name', 'Cambiado')
            ->call('save');

        $this->assertSame('BUSAN', DB::table('loading_ports')->where('port_id', 1)->value('port_name'));
    }

    public function test_el_nombre_no_se_repite_donde_la_base_no_tiene_duplicados(): void
    {
        DB::table('modality')->insert(['modality_id' => 1, 'modality_name' => 'FCL']);

        $this->pantalla('modalidades')
            ->call('create')
            ->set('form.modality_name', 'FCL')
            ->call('save')
            ->assertHasErrors(['form.modality_name' => 'unique']);
    }

    public function test_al_editar_el_nombre_propio_no_cuenta_como_repetido(): void
    {
        DB::table('modality')->insert(['modality_id' => 1, 'modality_name' => 'FCL']);

        $this->pantalla('modalidades')
            ->call('edit', 1)
            ->call('save')
            ->assertHasNoErrors();
    }

    /** `pickup_place.created_at` y `modified_at` son `date`: ahí va solo el día. */
    public function test_la_auditoria_en_columnas_date_guarda_solo_la_fecha(): void
    {
        CatalogSchema::create(CatalogRegistry::find('lugares-recoleccion'), auditoria: 'date');

        $this->pantalla('lugares-recoleccion')
            ->call('create')
            ->set('form.name', 'Bodega norte')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(now()->toDateString(), DB::table('pickup_place')->value('created_at'));
    }

    /**
     * En Yii2 los tipos de cargo, compañías, campos de archivo, monedas, puertos
     * de descarga y días festivos eran solo del super administrador. Un
     * administrador normal ni siquiera los abre; los demás catálogos sí.
     */
    public function test_un_administrador_normal_no_abre_ni_escribe_los_catalogos_de_super_administrador(): void
    {
        DB::table('company')->insert(['company_id' => 1, 'name' => 'FTM', 'active' => 1]);

        $this->pantalla('companias', User::ROLE_ADMIN)->assertForbidden();
        $this->pantalla('buques', User::ROLE_ADMIN)->assertOk();

        $this->assertSame(1, DB::table('company')->count());
    }

    public function test_los_seis_catalogos_del_original_son_de_super_administrador(): void
    {
        $reservados = array_keys(array_filter(CatalogRegistry::all(), fn ($d) => $d->superAdmin));

        $this->assertSame(
            ['puertos-descarga', 'dias-festivos', 'monedas', 'companias', 'tipos-cargo', 'campos-archivo'],
            $reservados,
        );
    }

    /** Como `ChargeTypeController` en Yii2: sin deducción no hay impuestos que guardar. */
    public function test_un_cargo_no_deducible_guarda_los_impuestos_en_cero_y_los_oculta(): void
    {
        $pantalla = $this->pantalla('tipos-cargo')
            ->call('create')
            ->set('form.charge_type_name', 'Multa')
            ->set('form.tax_rate', '0.16')
            ->set('form.tax_retention', '0.04')
            ->set('form.non_deductible', true)
            ->assertDontSeeHtml('wire:model="form.tax_rate"')
            ->call('save')
            ->assertHasNoErrors();

        $fila = DB::table('charge_type')->first();

        $this->assertSame([0.0, 0.0, 1], [(float) $fila->tax_rate, (float) $fila->tax_retention, (int) $fila->non_deductible]);
        $pantalla->call('create')->assertSeeHtml('wire:model="form.tax_rate"');
    }

    public function test_un_cargo_deducible_sigue_exigiendo_sus_tasas(): void
    {
        $this->pantalla('tipos-cargo')
            ->call('create')
            ->set('form.charge_type_name', 'Flete')
            ->set('form.tax_rate', '')
            ->call('save')
            ->assertHasErrors(['form.tax_rate' => 'required']);
    }

    public function test_el_rfc_y_el_codigo_postal_de_la_compania_llevan_el_formato_del_sat(): void
    {
        $this->pantalla('companias')
            ->call('create')
            ->set('form.name', 'FTM')
            ->set('form.rfc', 'no-es-rfc')
            ->set('form.postal_code', '4410')
            ->call('save')
            ->assertHasErrors(['form.rfc' => 'regex', 'form.postal_code' => 'regex']);

        $this->pantalla('companias')
            ->call('create')
            ->set('form.name', 'FTM')
            ->set('form.rfc', 'FTM010101AAA')
            ->set('form.postal_code', '44100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('44100', DB::table('company')->value('postal_code'));
    }

    public function test_el_regimen_de_la_compania_sale_del_catalogo_del_sat(): void
    {
        $this->pantalla('companias')
            ->call('create')
            ->set('form.name', 'FTM')
            ->set('form.regimen_fiscal', '999')
            ->call('save')
            ->assertHasErrors(['form.regimen_fiscal' => 'in']);

        $this->pantalla('companias')
            ->call('create')
            ->set('form.name', 'FTM')
            ->set('form.regimen_fiscal', '626')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('626', (string) DB::table('company')->value('regimen_fiscal'));
    }

    /** El semáforo del original: se ve de un vistazo qué compañía puede timbrar. */
    public function test_el_listado_de_companias_dice_cual_puede_facturar_y_que_le_falta(): void
    {
        DB::table('company')->insert([
            ['company_id' => 1, 'name' => 'Lista', 'business_name' => 'Lista SA', 'rfc' => 'LIS010101AAA', 'regimen_fiscal' => '601', 'postal_code' => '44100', 'active' => 1],
            ['company_id' => 2, 'name' => 'Incompleta', 'business_name' => null, 'rfc' => 'INC010101AAA', 'regimen_fiscal' => '601', 'postal_code' => null, 'active' => 1],
        ]);

        $this->pantalla('companias')
            ->assertSee(__('Lista para facturar'))
            ->assertSee(__('No factura: falta :campos', ['campos' => __('Razón social').', '.__('C.P. del domicilio fiscal')]));
    }

    public function test_la_retencion_del_codigo_de_impuesto_es_un_numero(): void
    {
        $this->pantalla('codigos-impuesto')
            ->call('create')
            ->set('form.tax_code', 'IVA')
            ->set('form.tax_retention', 'texto')
            ->call('save')
            ->assertHasErrors(['form.tax_retention' => 'numeric']);

        $this->pantalla('codigos-impuesto')
            ->call('create')
            ->set('form.tax_code', 'IVA')
            ->set('form.tax_retention', '0.04')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0.04, (float) DB::table('tax_code')->value('tax_retention'));
    }

    public function test_la_baja_logica_no_pierde_el_registro_para_quien_ya_lo_usaba(): void
    {
        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'Manzanillo', 'deleted' => 0]);

        $this->pantalla('puertos-carga')->call('delete', 1);

        // El renglón sigue en la base: los bookings viejos que lo referencian
        // siguen pudiendo mostrar su nombre.
        $this->assertSame('Manzanillo', DB::table('loading_ports')->where('port_id', 1)->value('port_name'));
    }
}
