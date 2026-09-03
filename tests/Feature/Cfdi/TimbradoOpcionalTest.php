<?php

namespace Tests\Feature\Cfdi;

use App\Actions\Transactions\StampTransaction;
use App\Livewire\Parties\PartyManager;
use App\Livewire\Transactions\TransactionDetail;
use App\Models\Core\Transaction;
use App\Models\User;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\PacClient;
use App\Support\Notifications\NoticeFeed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\Support\FakePacClient;
use Tests\TestCase;

/**
 * El timbrado CFDI es un conector, no el único camino a la facturación.
 *
 * Timbrar es una obligación **mexicana**. Hasta que existió este interruptor, el
 * sistema no se podía instalar en un negocio que no factura al SAT sin que le
 * sobraran botones que no llevan a ningún lado y campos que nadie sabe llenar.
 *
 * Con `TIMBRADO_HABILITADO=false` las facturas se siguen emitiendo, imprimiendo
 * y cobrando igual; lo que desaparece es el CFDI.
 */
class TimbradoOpcionalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        Storage::fake('documentos');
        Http::preventStrayRequests();
        $this->app->instance(PacClient::class, new FakePacClient);

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN']]);
        DB::table('exchange')->insert([['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1]]);
        DB::table('booking')->insert([['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10]]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('charge_type')->insert([[
            'charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_name' => 'IVA',
            'tax_rate' => 0.16, 'tax_retention' => 0, 'non_deductible' => 0, 'product_code' => '78101800',
        ]]);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'company_id' => 1,
            'account' => 1, 'tran_number' => 'F-1', 'tran_date' => '2026-01-15', 'invoice_type' => 1,
        ]]);
        DB::table('charge')->insert([[
            'charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 2, 'price' => 1000,
            'description' => 'Flete',
        ]]);
    }

    private function admin(): User
    {
        $usuario = new User;
        $usuario->usr_id = 7;
        $usuario->role = User::ROLE_ADMIN;
        $usuario->access = User::ACCESS_INTERNAL;

        return $usuario;
    }

    private function detalle(): Testable
    {
        $this->actingAs($this->admin());

        return Livewire::test(TransactionDetail::class, ['transaction' => 1]);
    }

    public function test_con_timbrado_la_factura_se_puede_timbrar(): void
    {
        config(['timbrado.habilitado' => true]);

        $this->assertTrue($this->detalle()->instance()->canStamp());
    }

    public function test_sin_timbrado_la_pantalla_ya_no_lo_ofrece(): void
    {
        config(['timbrado.habilitado' => false]);

        $this->assertFalse($this->detalle()->instance()->canStamp());
    }

    /** El botón oculto no basta: la acción tiene que negarse por su cuenta. */
    public function test_sin_timbrado_la_pantalla_tampoco_deja_forzarlo(): void
    {
        config(['timbrado.habilitado' => false]);

        $this->detalle()->call('stamp')->assertForbidden();
    }

    /**
     * Defensa en profundidad: la comprobación va también en la acción, que es
     * lo único que protege si la llaman desde una consola o un trabajo en cola.
     */
    public function test_sin_timbrado_la_accion_se_niega_aunque_la_llamen_directo(): void
    {
        config(['timbrado.habilitado' => false]);

        $this->expectException(CfdiException::class);

        app(StampTransaction::class)->handle(Transaction::findOrFail(1));
    }

    /**
     * Cancelar SÍ sigue disponible: una instalación que dejó de facturar al SAT
     * todavía puede tener que cancelar lo que timbró antes.
     */
    public function test_sin_timbrado_lo_ya_timbrado_todavia_se_cancela(): void
    {
        config(['timbrado.habilitado' => false]);
        DB::table('transaction')->where('transc_id', 1)->update(['seal' => 'UUID-VIEJO']);

        $this->assertTrue($this->detalle()->instance()->canCancel());
    }

    public function test_sin_timbrado_desaparece_el_aviso_de_facturas_sin_timbrar(): void
    {
        $this->actingAs($this->admin());

        config(['timbrado.habilitado' => true]);
        $this->assertGreaterThan(0, (new NoticeFeed)->all()->where('grupo', 'timbrado')->count());

        // `NoticeFeed::all()` cachea cinco minutos, así que sin vaciar la caché
        // la segunda lectura devolvería la primera y la prueba pasaría en falso.
        config(['timbrado.habilitado' => false]);
        Cache::flush();

        $this->assertSame(0, (new NoticeFeed)->all()->where('grupo', 'timbrado')->count());
    }

    public function test_sin_timbrado_el_cliente_no_pide_los_campos_del_sat(): void
    {
        $this->actingAs($this->admin());

        config(['timbrado.habilitado' => true]);
        Livewire::test(PartyManager::class, ['mode' => 'client'])
            ->call('edit', 1)
            ->assertSee('Uso del CFDI');

        config(['timbrado.habilitado' => false]);
        Livewire::test(PartyManager::class, ['mode' => 'client'])
            ->call('edit', 1)
            ->assertDontSee('Uso del CFDI')
            ->assertSee('Correos para facturas');
    }
}
