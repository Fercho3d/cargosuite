<?php

namespace Tests\Feature\Parties;

use App\Livewire\Parties\PartyForm;
use App\Livewire\Parties\PartyManager;
use App\Models\Core\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** Clientes y proveedores: la misma pantalla con distintos campos. */
class PartyManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN']]);
        DB::table('invoice_use')->insert([['code' => 'G03', 'name' => 'Gastos en general'], ['code' => 'P01', 'name' => 'Por definir']]);
        DB::table('pay_method')->insert([['code' => 'PUE', 'name' => 'Pago en una exhibición']]);
        DB::table('pay_form')->insert([['code' => '03', 'name' => 'Transferencia']]);
    }

    /** Un cliente con todo lo que exige el CFDI 4.0. */
    private function clienteValido(Testable $ficha): Testable
    {
        return $ficha
            ->set('form.fullName', 'Lubricantes de América')
            ->set('form.rfc', 'LAM010101AAA')
            ->set('form.address', 'Av. Vallarta 1234')
            ->set('form.postal_code', '44100')
            ->set('form.regimen_fiscal_id', '601');
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function pantalla(string $modo = 'client', int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(PartyManager::class, ['mode' => $modo]);
    }

    private function ficha(string $modo = 'client', ?int $party = null, int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(PartyForm::class, ['mode' => $modo, 'party' => $party]);
    }

    public function test_crear_un_cliente_con_sus_datos_fiscales(): void
    {
        $this->clienteValido($this->ficha())
            ->set('form.invoice_use', 'G03')
            ->set('form.pay_method', 'PUE')
            ->set('form.pay_form', '03')
            ->call('save')
            ->assertHasNoErrors();

        $cliente = DB::table('client')->first();

        $this->assertSame('Lubricantes de América', $cliente->fullName);
        $this->assertSame(['601', 'G03', 'PUE', '03'], [$cliente->regimen_fiscal_id, $cliente->invoice_use, $cliente->pay_method, $cliente->pay_form]);
    }

    /** El original escribía `modified_by` también al dar de alta. */
    public function test_el_alta_deja_quien_lo_modifico(): void
    {
        $this->clienteValido($this->ficha())->call('save')->assertHasNoErrors();

        $cliente = DB::table('client')->first();

        $this->assertSame((int) $cliente->created_by, (int) $cliente->modified_by);
        $this->assertNotNull($cliente->modified_at);
    }

    /** La columna `email` no acepta nulos: un cliente sin correo se guarda vacío, como en el original. */
    public function test_editar_un_cliente_sin_correo(): void
    {
        DB::table('client')->insert(['client_id' => 124, 'fullName' => 'SMC GRAPHICS INC', 'email' => '']);

        // Es un cliente extranjero sin datos del SAT: se edita sin timbrado.
        config(['timbrado.habilitado' => false]);

        $this->ficha(party: 124)
            ->set('form.city', 'VANCOUVER')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('', DB::table('client')->where('client_id', 124)->value('email'));
        $this->assertSame('VANCOUVER', DB::table('client')->where('client_id', 124)->value('city'));
    }

    /** CFDI 4.0 valida el RFC, el domicilio fiscal (CP) y el régimen del receptor. */
    public function test_con_timbrado_los_datos_del_cfdi_son_obligatorios(): void
    {
        $this->ficha()
            ->set('form.fullName', 'Cliente')
            ->call('save')
            ->assertHasErrors(['form.rfc' => 'required', 'form.address' => 'required', 'form.postal_code' => 'required', 'form.regimen_fiscal_id' => 'required']);
    }

    public function test_sin_timbrado_los_datos_del_cfdi_no_se_piden(): void
    {
        config(['timbrado.habilitado' => false]);

        $campos = array_keys($this->ficha()->instance()->fields());

        $this->assertNotContains('regimen_fiscal_id', $campos);
        $this->ficha()->set('form.fullName', 'Cliente')->call('save')->assertHasNoErrors();
    }

    public function test_el_rfc_sigue_el_formato_del_sat_y_se_guarda_en_mayusculas(): void
    {
        $this->clienteValido($this->ficha())
            ->set('form.rfc', 'NO-ES-RFC')
            ->call('save')
            ->assertHasErrors(['form.rfc' => 'regex']);

        $this->clienteValido($this->ficha())
            ->set('form.rfc', 'xaxx010101000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('XAXX010101000', DB::table('client')->value('rfc'));
    }

    public function test_el_codigo_postal_del_cfdi_es_de_cinco_digitos(): void
    {
        $this->clienteValido($this->ficha())
            ->set('form.postal_code', '4410')
            ->call('save')
            ->assertHasErrors(['form.postal_code' => 'regex']);
    }

    public function test_el_regimen_y_el_uso_salen_de_sus_catalogos(): void
    {
        $this->clienteValido($this->ficha())
            ->set('form.regimen_fiscal_id', '999')
            ->set('form.invoice_use', 'Z99')
            ->call('save')
            ->assertHasErrors(['form.regimen_fiscal_id' => 'in', 'form.invoice_use' => 'in']);
    }

    /** `fullName` es `varchar(100)` y la conexión es estricta: más largo reventaba al guardar. */
    public function test_las_longitudes_son_las_de_las_columnas(): void
    {
        $this->clienteValido($this->ficha())
            ->set('form.fullName', str_repeat('a', 101))
            ->set('form.email', str_repeat('a', 45).'@x.com')
            ->set('form.city', str_repeat('c', 26))
            ->call('save')
            ->assertHasErrors(['form.fullName' => 'max', 'form.email' => 'max', 'form.city' => 'max']);
    }

    /** `phone` es `int(11)`: se captura como se lee pero se guarda solo con dígitos. */
    public function test_el_telefono_se_limpia_y_se_guarda_como_entero(): void
    {
        $this->clienteValido($this->ficha())
            ->set('form.phone', '(55) 123-4567')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(551234567, (int) DB::table('client')->value('phone'));
    }

    public function test_un_telefono_que_no_cabe_en_la_columna_se_rechaza_con_aviso(): void
    {
        $this->clienteValido($this->ficha())
            ->set('form.phone', '33 1234 5678')
            ->call('save')
            ->assertHasErrors('form.phone')
            ->assertSee('no cabe en el campo');
    }

    /** Hay clientes con el teléfono ya guardado como entero: su edición no debe romperse. */
    public function test_un_telefono_existente_se_conserva_al_editar(): void
    {
        DB::table('client')->insert(['client_id' => 7, 'fullName' => 'Frialsa', 'phone' => 2147483647]);
        config(['timbrado.habilitado' => false]);

        $this->ficha(party: 7)
            ->assertSet('form.phone', '2147483647')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2147483647, (int) DB::table('client')->where('client_id', 7)->value('phone'));
    }

    /** `email_notification` es `varchar(1000)`: cabe una lista larga, pero cada correo tiene que ser válido. */
    public function test_la_lista_de_correos_admite_hasta_mil_caracteres_y_valida_cada_uno(): void
    {
        $lista = implode(', ', array_map(fn ($i) => "contacto{$i}@lubricantes-de-america.com.mx", range(1, 12)));
        $this->assertGreaterThan(255, strlen($lista));

        $this->clienteValido($this->ficha())
            ->set('form.email_notification', $lista)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($lista, DB::table('client')->value('email_notification'));

        $this->clienteValido($this->ficha())
            ->set('form.email_notification', 'bueno@x.com, esto-no-es-correo')
            ->call('save')
            ->assertHasErrors('form.email_notification');
    }

    /** Lo que leen la propuesta de servicios, la confirmación y su PDF. */
    public function test_se_capturan_los_campos_que_lee_la_operacion(): void
    {
        $this->clienteValido($this->ficha())
            ->set('form.match_pickup_place', true)
            ->set('form.notification_notes', 'Avisar a tráfico antes de recolectar')
            ->set('form.address2', 'Col. Americana')
            ->set('form.country', 'México')
            ->call('save')
            ->assertHasNoErrors();

        $cliente = DB::table('client')->first();

        $this->assertSame(
            [1, 'Avisar a tráfico antes de recolectar', 'Col. Americana', 'México'],
            [(int) $cliente->match_pickup_place, $cliente->notification_notes, $cliente->address2, $cliente->country],
        );
    }

    /** Sin tipo, el proveedor no aparece en ningún selector del booking. */
    public function test_el_tipo_del_proveedor_es_obligatorio(): void
    {
        $this->ficha('provider')
            ->set('form.fullName', 'Proveedor')
            ->call('save')
            ->assertHasErrors(['form.type_id' => 'required']);

        $this->ficha('provider')
            ->set('form.fullName', 'Proveedor')
            ->set('form.type_id', '9')
            ->call('save')
            ->assertHasErrors(['form.type_id' => 'in']);
    }

    public function test_el_listado_de_proveedores_muestra_el_tipo(): void
    {
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Maersk', 'type_id' => 1]]);

        $this->pantalla('provider')->assertSee('Naviera');
    }

    public function test_el_nombre_es_obligatorio(): void
    {
        $this->ficha()
            ->set('form.fullName', '')
            ->call('save')
            ->assertHasErrors('form.fullName');

        $this->assertSame(0, DB::table('client')->count());
    }

    public function test_el_correo_debe_ser_valido(): void
    {
        $this->ficha()
            ->set('form.fullName', 'Cliente')
            ->set('form.email', 'no-es-un-correo')
            ->call('save')
            ->assertHasErrors('form.email');
    }

    /** Los campos fiscales solo tienen sentido en el cliente: es quien recibe el CFDI. */
    public function test_el_proveedor_no_pide_datos_de_cfdi_y_si_pide_tipo(): void
    {
        $campos = array_keys($this->pantalla('provider')->instance()->fields());

        $this->assertContains('type_id', $campos);
        $this->assertNotContains('regimen_fiscal_id', $campos);
        $this->assertNotContains('invoice_use', $campos);
    }

    public function test_editar_un_proveedor(): void
    {
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Proveedor Uno', 'type_id' => 2]]);

        $this->ficha('provider', 1)
            ->assertSet('form.fullName', 'Proveedor Uno')
            ->set('form.city', 'Manzanillo')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Manzanillo', DB::table('provider')->where('provider_id', 1)->value('city'));
        $this->assertSame(1, DB::table('provider')->count());
    }

    public function test_la_ficha_del_proveedor_muestra_sus_servicios(): void
    {
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Proveedor Uno', 'type_id' => 2]]);
        DB::table('charge_type')->insert([['charge_type_id' => 1, 'charge_type_name' => 'Flete']]);
        DB::table('service')->insert([
            ['service_id' => 1, 'type' => 2, 'provider_id' => 1, 'charge_type_id' => 1, 'description' => 'Flete Manzanillo', 'price' => 42500, 'active' => 1],
        ]);

        $this->ficha('provider', 1)
            ->assertSee('Flete Manzanillo')
            ->assertSee('42,500.00');
    }

    public function test_guardar_regresa_a_la_lista_con_su_filtro(): void
    {
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Proveedor Uno', 'type_id' => 2]]);
        $this->actingAs($this->usuario());

        Livewire::withQueryParams(['volver' => '/terceros/proveedores?q=uno&page=3'])
            ->test(PartyForm::class, ['mode' => 'provider', 'party' => 1])
            ->call('save')
            ->assertRedirect('/terceros/proveedores?q=uno&page=3');
    }

    public function test_editar_desde_la_lista_lleva_su_busqueda(): void
    {
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Proveedor Uno', 'type_id' => 2]]);

        $this->pantalla('provider')
            ->set('search', 'uno')
            ->assertSeeHtml(e(route('parties.providers.edit', [1, 'volver' => '/terceros/proveedores?q=uno'])));
    }

    public function test_editar_servicios_regresa_a_la_ficha(): void
    {
        DB::table('provider')->insert([['provider_id' => 1, 'fullName' => 'Proveedor Uno', 'type_id' => 2]]);

        $url = $this->ficha('provider', 1)->instance()->servicesUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametros);

        $this->assertSame(['2', '1'], [$parametros['tipo'], $parametros['tercero']]);
        $this->assertStringStartsWith('/terceros/proveedores/1/editar?volver=', $parametros['volver']);
    }

    public function test_la_busqueda_filtra(): void
    {
        DB::table('client')->insert([
            ['client_id' => 1, 'fullName' => 'Lubricantes de América'],
            ['client_id' => 2, 'fullName' => 'Star Juice'],
        ]);

        $filas = $this->pantalla()->set('search', 'star')->viewData('filas');

        $this->assertCount(1, $filas->items());
        $this->assertSame('Star Juice', $filas->items()[0]->fullName);
    }

    /** La tabla guarda contraseña y llaves del portal: el listado no las trae. */
    public function test_el_listado_solo_trae_las_columnas_que_pinta(): void
    {
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Star Juice']]);

        $fila = (array) $this->pantalla()->viewData('filas')->items()[0];

        $this->assertEqualsCanonicalizing(['client_id', 'fullName', 'rfc', 'email', 'city', 'phone'], array_keys($fila));
    }

    public function test_quien_no_es_administrador_no_escribe(): void
    {
        $this->ficha('client', null, User::ROLE_USER)->assertForbidden();
    }

    /**
     * Los avisos (confirmación del booking, factura timbrada) van al correo
     * principal Y a la lista de notificación, sin repetidos ni inválidos, como
     * `Client::getNotificationEmails()` en Yii2. Antes era uno u otro.
     */
    public function test_los_correos_de_aviso_unen_el_principal_con_la_lista(): void
    {
        $cliente = new Client(['email' => 'contacto@x.mx', 'email_notification' => 'trafico@x.mx; contacto@x.mx, no-es-correo']);

        $this->assertSame(['contacto@x.mx', 'trafico@x.mx'], $cliente->notificationEmails());
    }

    public function test_sin_lista_de_aviso_queda_solo_el_correo_principal(): void
    {
        $cliente = new Client(['email' => 'contacto@x.mx', 'email_notification' => null]);

        $this->assertSame(['contacto@x.mx'], $cliente->notificationEmails());
    }

    /** Se baja todo el filtro, no la página, con las etiquetas de los selectores en vez de la clave. */
    public function test_el_listado_se_exporta_a_csv(): void
    {
        DB::table('provider')->insert([
            ['provider_id' => 1, 'fullName' => 'Naviera Uno', 'type_id' => 1, 'rfc' => 'NAV010101AAA', 'email' => 'ops@naviera.mx'],
            ['provider_id' => 2, 'fullName' => 'Transportes Dos', 'type_id' => 2, 'rfc' => null, 'email' => ''],
        ]);

        $csv = $this->pantalla('provider')
            ->set('search', 'naviera')
            ->call('export')
            ->assertFileDownloaded()
            ->effects['download']['content'] ?? '';

        $lineas = array_values(array_filter(explode("\n", trim(base64_decode($csv)))));

        $this->assertStringContainsString('"Nombre o razón social"', $lineas[0]);
        $this->assertStringContainsString('"Tipo de proveedor"', $lineas[0]);
        $this->assertCount(2, $lineas);
        $this->assertStringContainsString('"Naviera Uno",NAV010101AAA,ops@naviera.mx', $lineas[1]);
        $this->assertStringEndsWith(',Naviera', $lineas[1]);
    }
}
