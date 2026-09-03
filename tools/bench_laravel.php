<?php

/**
 * Mide las mismas pantallas que bench_legacy.php, ahora con el motor de Laravel.
 * Se ejecuta con:  php artisan tinker --execute="require '<ruta>';"
 */

use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Facades\DB;

$runs = 7;

$bench = function (string $label, callable $fn) use ($runs) {
    $times = [];
    $rows = 0;

    for ($i = 0; $i < $runs; $i++) {
        DB::disconnect();
        $t = microtime(true);
        $rows = $fn();
        $times[] = (microtime(true) - $t) * 1000;
    }

    sort($times);
    printf(
        "%-28s  filas=%-7d  min=%8.1f ms  mediana=%8.1f ms  max=%8.1f ms\n",
        $label,
        $rows,
        $times[0],
        $times[intdiv(count($times), 2)],
        $times[count($times) - 1]
    );
};

$page = function (array $filters, int $perPage = 100) {
    $p = TransactionQuery::make(TransactionFilters::make($filters))->paginate($perPage, 1);
    $p->total();

    return $p->count();
};

$bench('invoice (grid, 100 filas)', fn () => $page(['type' => [0]]));
$bench('bill (grid, 100 filas)', fn () => $page(['type' => [1, 2], 'paymentMode' => true]));
$bench('all (grid, 100 filas)', fn () => $page([]));

$booking = (int) DB::table('transaction')
    ->whereNotNull('booking')
    ->groupBy('booking')
    ->orderByRaw('COUNT(*) DESC')
    ->value('booking');

echo "booking de prueba: $booking\n";

$bench('index (1 booking)', fn () => TransactionQuery::make(
    TransactionFilters::make(['booking' => $booking, 'cancelled' => 0])
)->get()->count());

$bench('bill + filtro Unpaid', fn () => $page(['type' => [1, 2], 'paymentMode' => true, 'paid' => 0]));

$bench('totales de todo el filtro', function () {
    TransactionQuery::make(TransactionFilters::make(['type' => [0]]))->totals();

    return 1;
});
