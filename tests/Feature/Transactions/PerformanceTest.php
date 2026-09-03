<?php

namespace Tests\Feature\Transactions;

use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Presupuesto de tiempo de las pantallas del módulo.
 *
 * No busca medir con precisión de laboratorio —para eso está
 * `docs/benchmark-transactions.md`—, sino avisar si una pantalla vuelve a
 * degradarse en un orden de magnitud. Los topes están holgados a propósito:
 * en esta máquina las pantallas rondan los 10-30 ms y el original tardaba
 * entre 9 y 31 SEGUNDOS.
 */
#[Group('performance')]
class PerformanceTest extends LegacyDatabaseTestCase
{
    /** Milisegundos que tarda la mediana de tres corridas. */
    private function median(callable $fn): float
    {
        $times = [];

        for ($i = 0; $i < 3; $i++) {
            $start = microtime(true);
            $fn();
            $times[] = (microtime(true) - $start) * 1000;
        }

        sort($times);

        return $times[1];
    }

    private function screen(array $filters): callable
    {
        return function () use ($filters) {
            $page = TransactionQuery::make(TransactionFilters::make($filters))->paginate(100, 1);
            $page->total();
        };
    }

    public function test_las_pantallas_principales_responden_en_menos_de_medio_segundo(): void
    {
        $budget = 500;

        foreach ([
            'facturas' => ['type' => [0]],
            'costos' => ['type' => [1, 2], 'paymentMode' => true],
            'todas' => [],
        ] as $name => $filters) {
            $ms = $this->median($this->screen($filters));

            $this->assertLessThan(
                $budget,
                $ms,
                sprintf('La pantalla "%s" tardó %.1f ms (tope %d ms).', $name, $ms, $budget)
            );
        }
    }

    public function test_el_filtro_por_estado_de_pago_responde_en_menos_de_tres_segundos(): void
    {
        // Es el caso caro: depende de los agregados, así que no puede acotarse a
        // los IDs de la página y recorre el conjunto filtrado completo.
        $ms = $this->median($this->screen(['type' => [1, 2], 'paymentMode' => true, 'paid' => 0]));

        $this->assertLessThan(3000, $ms, sprintf('El filtro "sin pagar" tardó %.1f ms.', $ms));
    }

    public function test_el_listado_de_un_booking_responde_en_menos_de_un_cuarto_de_segundo(): void
    {
        $booking = TransactionQuery::make(TransactionFilters::make([]))->idQuery()->limit(1)->value('transc_id');

        $ms = $this->median(function () use ($booking) {
            TransactionQuery::make(TransactionFilters::make(['booking' => $booking]))->get();
        });

        $this->assertLessThan(250, $ms, sprintf('El listado por booking tardó %.1f ms.', $ms));
    }
}
