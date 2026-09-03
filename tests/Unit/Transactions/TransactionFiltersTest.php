<?php

namespace Tests\Unit\Transactions;

use App\Queries\TransactionFilters;
use PHPUnit\Framework\TestCase;

/**
 * Lógica de los filtros, sin base de datos de por medio.
 */
class TransactionFiltersTest extends TestCase
{
    public function test_interpreta_el_rango_de_fechas_de_la_rejilla(): void
    {
        $this->assertSame(
            ['2025-01-05', '2025-03-31'],
            TransactionFilters::parseRange('05/01/2025 - 31/03/2025')
        );
    }

    public function test_descarta_rangos_mal_formados(): void
    {
        $this->assertNull(TransactionFilters::parseRange(null));
        $this->assertNull(TransactionFilters::parseRange(''));
        $this->assertNull(TransactionFilters::parseRange('05/01/2025'), 'falta el segundo extremo');
        $this->assertNull(TransactionFilters::parseRange('2025-01-05 - 2025-03-31'), 'formato ISO, no dd/mm/aaaa');
        $this->assertNull(TransactionFilters::parseRange('31/02/2025 - 31/03/2025'), '31 de febrero no existe');
    }

    public function test_normaliza_el_tipo_venga_como_escalar_o_como_lista(): void
    {
        $this->assertSame([], TransactionFilters::make([])->types());
        $this->assertSame([], TransactionFilters::make(['type' => []])->types());
        $this->assertSame([0], TransactionFilters::make(['type' => 0])->types());
        $this->assertSame([1, 2], TransactionFilters::make(['type' => [1, 2]])->types());
        $this->assertSame([1, 2], TransactionFilters::make(['type' => ['1', '2']])->types());
    }

    public function test_reconoce_los_filtros_que_obligan_a_agregar_antes(): void
    {
        $this->assertFalse(TransactionFilters::make([])->needsAggregateFilter());
        $this->assertTrue(TransactionFilters::make(['paid' => 0])->needsAggregateFilter(), 'Unpaid depende del total pagado');
        $this->assertTrue(TransactionFilters::make(['paid' => 1])->needsAggregateFilter());
        $this->assertTrue(TransactionFilters::make(['onlyUndpaid' => true])->needsAggregateFilter());
    }

    public function test_distingue_el_agrupado_por_transaccion_del_de_reportes(): void
    {
        $this->assertTrue(TransactionFilters::make([])->groupedByTransaction());
        $this->assertTrue(TransactionFilters::make(['groupBy' => 'transaction.transc_id'])->groupedByTransaction());
        $this->assertFalse(TransactionFilters::make(['groupBy' => 'booking'])->groupedByTransaction());
        $this->assertFalse(TransactionFilters::make(['groupBy' => 'vendor'])->groupedByTransaction());
    }

    public function test_ignora_claves_desconocidas(): void
    {
        $filters = TransactionFilters::make(['type' => [0], 'noExisteEstaPropiedad' => 'x']);

        $this->assertSame([0], $filters->types());
        $this->assertFalse(property_exists($filters, 'noExisteEstaPropiedad'));
    }
}
