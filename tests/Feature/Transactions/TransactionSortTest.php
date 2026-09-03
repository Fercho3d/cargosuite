<?php

namespace Tests\Feature\Transactions;

use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Ordenar por cualquier columna del listado.
 *
 * El motor resuelve la página en dos fases y la primera —la barata— solo conoce
 * las columnas de `transaction`. Por eso las columnas de dinero, que se calculan
 * en la segunda, tienen que forzar la consulta completa; si no, se ordenaría por
 * un valor que en ese momento todavía no existe.
 */
class TransactionSortTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        $this->seedFixture();
    }

    private function seedFixture(): void
    {
        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN'],
            ['account_id' => 2, 'account_name' => 'Dólares', 'default' => null, 'prefix' => 'USD'],
        ]);
        DB::table('exchange')->insert([
            ['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2025-01-10', 'account' => 1],
        ]);
        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'IVA 0', 'tax_rate' => 0, 'tax_retention' => 0, 'non_deductible' => 0],
        ]);
        DB::table('company')->insert([
            ['company_id' => 1, 'name' => 'ZZZ', 'rfc' => 'AAA010101AAA'],
            ['company_id' => 2, 'name' => 'AAA', 'rfc' => 'BBB010101BBB'],
        ]);
        DB::table('booking')->insert(['booking_id' => 1, 'booking_number' => 'BK-1', 'mode' => 10]);
        DB::table('client')->insert([
            ['client_id' => 1, 'fullName' => 'ZETA CLIENTE'],
            ['client_id' => 2, 'fullName' => 'ALFA CLIENTE'],
            ['client_id' => 3, 'fullName' => 'MEDIO CLIENTE'],
        ]);

        $base = ['booking' => 1, 'invoice_type' => 1, 'cancelled' => 0, 'tran_type' => 0, 'tran_date' => '2025-01-10'];

        DB::table('transaction')->insert([
            ['transc_id' => 1, 'tran_number' => 'F-1', 'customer' => 1, 'company_id' => 1, 'account' => 1] + $base,
            ['transc_id' => 2, 'tran_number' => 'F-2', 'customer' => 2, 'company_id' => 2, 'account' => 2] + $base,
            ['transc_id' => 3, 'tran_number' => 'F-3', 'customer' => 3, 'company_id' => 1, 'account' => 1] + $base,
        ]);

        // Importes a propósito en desorden respecto del id: 300 / 100 / 200.
        DB::table('charge')->insert([
            ['charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 1, 'price' => 300],
            ['charge_id' => 2, 'transaction' => 2, 'type' => 1, 'quantity' => 1, 'price' => 100],
            ['charge_id' => 3, 'transaction' => 3, 'type' => 1, 'quantity' => 1, 'price' => 200],
        ]);
    }

    /** @return int[] */
    private function idsOrdenados(string $sort, string $direction): array
    {
        $filtros = TransactionFilters::make(['sort' => $sort, 'direction' => $direction]);

        return TransactionQuery::make($filtros)
            ->paginate(50, 1)
            ->getCollection()
            ->pluck('transc_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_ordena_por_columnas_de_la_transaccion(): void
    {
        $this->assertSame([1, 2, 3], $this->idsOrdenados('tran_number', 'asc'));
        $this->assertSame([3, 2, 1], $this->idsOrdenados('tran_number', 'desc'));
    }

    /** «Aplicado a» es el cliente o, si no hay, el proveedor. */
    public function test_ordena_por_la_contraparte(): void
    {
        // ALFA (2), MEDIO (3), ZETA (1).
        $this->assertSame([2, 3, 1], $this->idsOrdenados('applied_to', 'asc'));
    }

    public function test_ordena_por_compania_y_por_divisa(): void
    {
        // AAA es la compañía 2; ZZZ agrupa a la 1 y la 3.
        $this->assertSame(2, $this->idsOrdenados('company', 'asc')[0]);
        // MXN antes que USD: la 2 es la única en dólares.
        $this->assertSame(2, $this->idsOrdenados('currency', 'desc')[0]);
    }

    /**
     * Lo que no se podía hacer: ordenar por un importe. No existe en la fase 1,
     * así que la consulta tiene que caer sola a la completa.
     */
    public function test_ordena_por_los_importes_calculados(): void
    {
        $this->assertSame([2, 3, 1], $this->idsOrdenados('total_amount', 'asc'));
        $this->assertSame([1, 3, 2], $this->idsOrdenados('total_amount', 'desc'));
        $this->assertSame([2, 3, 1], $this->idsOrdenados('amount_original', 'asc'));
        // El «Estado» del renglón se lee del saldo pendiente.
        $this->assertSame([2, 3, 1], $this->idsOrdenados('left_to_pay', 'asc'));
    }

    public function test_ordenar_por_un_importe_obliga_a_la_consulta_completa(): void
    {
        $porFecha = TransactionQuery::make(TransactionFilters::make(['sort' => 'tran_date']));
        $porTotal = TransactionQuery::make(TransactionFilters::make(['sort' => 'total_amount']));

        $this->assertTrue($porFecha->sortFitsIdQuery());
        $this->assertFalse($porTotal->sortFitsIdQuery());
    }

    /** Una columna inventada no rompe la consulta ni se cuela al SQL. */
    public function test_una_columna_desconocida_cae_al_orden_por_omision(): void
    {
        $this->assertSame([3, 2, 1], $this->idsOrdenados('t.tran_date; DROP TABLE transaction', 'desc'));
    }
}
