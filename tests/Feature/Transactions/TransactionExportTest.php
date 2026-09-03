<?php

namespace Tests\Feature\Transactions;

use App\Livewire\Transactions\TransactionTable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Descarga del listado para abrirlo en Excel.
 *
 * Lo que de verdad hay que fijar aquí es que baje el FILTRO COMPLETO y no la
 * página: el `{export}` del sistema viejo baja solo lo pintado, y esa es
 * justamente la diferencia que se decidió introducir.
 */
class TransactionExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        $this->seedFixture();
    }

    private function seedFixture(): void
    {
        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN']]);
        DB::table('exchange')->insert([['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1]]);
        DB::table('charge_type')->insert([['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0, 'tax_retention' => 0, 'non_deductible' => 0]]);
        DB::table('company')->insert(['company_id' => 1, 'name' => 'FTA', 'rfc' => 'AAA010101AAA']);
        DB::table('booking')->insert(['booking_id' => 1, 'booking_number' => 'BK-1', 'mode' => 10]);
        DB::table('client')->insert(['client_id' => 1, 'fullName' => 'CLIENTE ÁÉÍ']);

        // 12 facturas: con 5 por página, la descarga tiene que traer las 12.
        for ($i = 1; $i <= 12; $i++) {
            DB::table('transaction')->insert([
                'transc_id' => $i, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'company_id' => 1,
                'account' => 1, 'tran_number' => "F-{$i}", 'tran_date' => '2026-01-15',
                'invoice_type' => 1, 'cancelled' => 0, 'pdf_attach' => '',
            ]);
            DB::table('charge')->insert([
                'charge_id' => $i, 'transaction' => $i, 'type' => 1, 'quantity' => 1, 'price' => 100 * $i,
            ]);
        }
    }

    private function admin(): User
    {
        $u = new User;
        $u->usr_id = 5;
        $u->role = User::ROLE_ADMIN;

        return $u;
    }

    private function descarga(array $parametros = []): string
    {
        $respuesta = $this->actingAs($this->admin())
            ->get(route('transactions.export', ['screen' => 'invoice'] + $parametros))
            ->assertOk();

        return $respuesta->streamedContent();
    }

    public function test_baja_el_filtro_completo_y_no_solo_la_pagina(): void
    {
        $csv = $this->descarga();
        $renglones = array_filter(explode("\n", trim($csv)));

        // 12 documentos + el encabezado.
        $this->assertCount(13, $renglones);
    }

    public function test_respeta_el_filtro_de_la_direccion(): void
    {
        $csv = $this->descarga(['num' => 'F-7']);

        $this->assertStringContainsString('F-7', $csv);
        $this->assertStringNotContainsString('F-8', $csv);
    }

    /** Sin la marca de orden de bytes, Excel se come los acentos. */
    public function test_el_archivo_abre_con_acentos_en_excel(): void
    {
        $csv = $this->descarga();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('CLIENTE ÁÉÍ', $csv);
    }

    /**
     * Los importes van en crudo: con separador de miles Excel los toma como
     * texto y deja de poder sumarlos, que es para lo que se exporta.
     */
    public function test_los_importes_van_sin_formato(): void
    {
        $csv = $this->descarga(['num' => 'F-12']);

        $this->assertStringContainsString('1200', $csv);
        $this->assertStringNotContainsString('1,200.00', $csv);
    }

    public function test_quien_no_es_administrador_no_descarga(): void
    {
        $usuario = new User;
        $usuario->usr_id = 9;
        $usuario->role = User::ROLE_USER;

        $this->actingAs($usuario)
            ->get(route('transactions.export', ['screen' => 'invoice']))
            ->assertForbidden();
    }

    public function test_una_pantalla_inventada_no_existe(): void
    {
        $this->actingAs($this->admin())
            ->get(route('transactions.export', ['screen' => 'loquesea']))
            ->assertNotFound();
    }

    /** El botón lleva el filtro puesto, para que el archivo sea lo que se ve. */
    public function test_el_boton_arrastra_el_filtro_de_la_pantalla(): void
    {
        $this->actingAs($this->admin());

        $componente = Livewire::test(TransactionTable::class, ['screen' => 'bill'])
            ->set('tranNumber', 'F-3')
            ->set('showCancelled', '2');

        $url = $componente->instance()->exportUrl();

        $this->assertStringContainsString('/exportar/bill', $url);
        $this->assertStringContainsString('num=F-3', $url);
        $this->assertStringContainsString('canc=2', $url);
    }
}
