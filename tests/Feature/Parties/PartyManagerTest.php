<?php

namespace Tests\Feature\Parties;

use App\Livewire\Parties\PartyManager;
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
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::create([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function pantalla(string $modo = 'client', int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(PartyManager::class, ['mode' => $modo]);
    }

    public function test_crear_un_cliente_con_sus_datos_fiscales(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('form.fullName', 'Lubricantes de América')
            ->set('form.rfc', 'LAM010101AAA')
            ->set('form.regimen_fiscal_id', '601')
            ->set('form.invoice_use', 'G03')
            ->call('save')
            ->assertHasNoErrors();

        $cliente = DB::table('client')->first();

        $this->assertSame('Lubricantes de América', $cliente->fullName);
        $this->assertSame('601', $cliente->regimen_fiscal_id);
    }

    public function test_el_nombre_es_obligatorio(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('form.fullName', '')
            ->call('save')
            ->assertHasErrors('form.fullName');

        $this->assertSame(0, DB::table('client')->count());
    }

    public function test_el_correo_debe_ser_valido(): void
    {
        $this->pantalla()
            ->call('create')
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

        $this->pantalla('provider')
            ->call('edit', 1)
            ->assertSet('form.fullName', 'Proveedor Uno')
            ->set('form.city', 'Manzanillo')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Manzanillo', DB::table('provider')->where('provider_id', 1)->value('city'));
        $this->assertSame(1, DB::table('provider')->count());
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

    public function test_quien_no_es_administrador_no_escribe(): void
    {
        $this->pantalla('client', User::ROLE_USER)->call('create')->assertForbidden();
    }
}
