<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\PortalHome;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * El portal y su acotación.
 *
 * Lo que se cuida aquí es que una cuenta de cliente o de proveedor **solo vea lo
 * suyo**: ni la operación de la empresa, ni los documentos de otro. La
 * pertenencia se comprueba en el servidor, no en la pantalla.
 */
class PortalAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        Storage::fake('documentos');

        $this->seedFixture();
    }

    private function seedFixture(): void
    {
        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN']]);
        DB::table('exchange')->insert([['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1]]);
        DB::table('client')->insert([
            ['client_id' => 1, 'fullName' => 'Cliente Uno'],
            ['client_id' => 2, 'fullName' => 'Cliente Dos'],
        ]);
        DB::table('booking')->insert([
            ['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0],
            ['booking_id' => 2, 'booking_number' => 'BK-2', 'client' => 2, 'mode' => 10, 'is_draft' => 0],
        ]);
        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0, 'tax_retention' => 0, 'non_deductible' => 0],
        ]);

        foreach ([[1, 1, 1], [2, 2, 2]] as [$id, $booking, $cliente]) {
            DB::table('transaction')->insert([
                'transc_id' => $id, 'booking' => $booking, 'tran_type' => 0, 'customer' => $cliente,
                'account' => 1, 'tran_number' => "F-{$id}", 'tran_date' => '2026-01-15', 'invoice_type' => 1,
                'pdf_attach' => "factura-{$id}.pdf",
            ]);
            DB::table('charge')->insert([
                'charge_id' => $id, 'transaction' => $id, 'type' => 1, 'quantity' => 1, 'price' => 100,
            ]);
            Storage::disk('documentos')->put("transactions/{$id}/pdf/factura-{$id}.pdf", 'contenido');
        }
    }

    private function clientePortal(int $clientId = 1): User
    {
        return User::forceCreate([
            'username' => "portal.cliente.{$clientId}", 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_USER, 'access' => User::ACCESS_CLIENT, 'client_id' => $clientId, 'status' => 1,
        ]);
    }

    private function interno(): User
    {
        return User::forceCreate([
            'username' => 'operador', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    public function test_una_cuenta_de_portal_no_entra_al_sistema_interno(): void
    {
        $this->actingAs($this->clientePortal())
            ->get(route('operations.bookings'))
            ->assertRedirect(route('portal'));
    }

    public function test_una_cuenta_interna_no_entra_al_portal(): void
    {
        $this->actingAs($this->interno())
            ->get(route('portal'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_el_portal_solo_enseña_los_documentos_del_cliente(): void
    {
        $this->actingAs($this->clientePortal(1));

        $documentos = Livewire::test(PortalHome::class)->viewData('documentos');

        $this->assertCount(1, $documentos->items());
        $this->assertSame('F-1', $documentos->items()[0]->tran_number);
    }

    public function test_el_portal_solo_enseña_los_embarques_del_cliente(): void
    {
        $this->actingAs($this->clientePortal(1));

        $embarques = Livewire::test(PortalHome::class)->set('tab', 'embarques')->viewData('embarques');

        $this->assertCount(1, $embarques);
        $this->assertSame('BK-1', $embarques->first()->booking_number);
    }

    /** Conocer el número de otra factura no debe alcanzar para bajarla. */
    public function test_no_se_puede_bajar_el_documento_de_otro_cliente(): void
    {
        $this->actingAs($this->clientePortal(1))
            ->get(route('portal.file', [2, 'pdf']))
            ->assertNotFound();
    }

    public function test_si_se_puede_bajar_el_documento_propio(): void
    {
        $this->actingAs($this->clientePortal(1))
            ->get(route('portal.file', [1, 'pdf']))
            ->assertOk();
    }

    /** La seguridad de la cuenta es de cualquiera con sesión, portal incluido. */
    public function test_una_cuenta_de_portal_puede_cuidar_su_cuenta(): void
    {
        $this->actingAs($this->clientePortal())
            ->get(route('security.show'))
            ->assertOk();
    }

    public function test_la_raiz_manda_a_cada_quien_a_su_sitio(): void
    {
        $this->actingAs($this->clientePortal())->get('/')->assertRedirect(route('portal'));
        $this->actingAs($this->interno())->get('/')->assertRedirect(route('dashboard'));
    }
}
