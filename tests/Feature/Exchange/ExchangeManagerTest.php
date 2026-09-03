<?php

namespace Tests\Feature\Exchange;

use App\Livewire\Exchange\ExchangeManager;
use App\Models\Core\Exchange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Tipos de cambio.
 *
 * La regla que más importa: no puede haber dos para la misma moneda el mismo
 * día, porque el motor de consulta une por esa pareja y duplicaría los importes.
 */
class ExchangeManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        Http::preventStrayRequests();

        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN'],
            ['account_id' => 2, 'account_name' => 'Dólares', 'default' => null, 'prefix' => 'USD'],
        ]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::create([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(ExchangeManager::class);
    }

    public function test_capturar_un_tipo_de_cambio(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '17.4321')
            ->call('save')
            ->assertHasNoErrors();

        $tipo = Exchange::first();

        $this->assertSame(17.4321, $tipo->exchange_value);
        $this->assertSame('2026-02-10', $tipo->date_exchange->toDateString());
    }

    /** El motor une por (fecha, moneda): dos filas duplicarían los importes. */
    public function test_no_se_repite_la_misma_moneda_el_mismo_dia(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-02-10', 'account' => 2, 'exchange_value' => 17]);

        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '18')
            ->call('save')
            ->assertHasErrors('date');

        $this->assertSame(1, Exchange::count());
    }

    public function test_dos_monedas_el_mismo_dia_si_conviven(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-02-10', 'account' => 1, 'exchange_value' => 1]);

        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '17.5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Exchange::count());
    }

    public function test_el_tipo_de_cambio_no_puede_ser_cero(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '0')
            ->call('save')
            ->assertHasErrors('value');
    }

    public function test_editar_no_choca_consigo_mismo(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-02-10', 'account' => 2, 'exchange_value' => 17]);

        $this->pantalla()
            ->call('edit', 1)
            ->set('value', '17.9')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(17.9, Exchange::find(1)->exchange_value);
    }

    public function test_traer_el_del_dia_avisa_cuando_el_dof_no_contesta(): void
    {
        Http::fake(['sidofqa.segob.gob.mx/*' => Http::response(['ListaIndicadores' => []])]);

        $this->pantalla()->call('fetchToday')->assertHasErrors('fetch');
    }

    public function test_quien_no_es_administrador_no_entra(): void
    {
        $this->actingAs($this->usuario(User::ROLE_USER));

        Livewire::test(ExchangeManager::class)->assertForbidden();
    }
}
