<?php

/**
 * Compara el tiempo del motor de solicitudes de pago contra el SQL real de Yii2.
 *
 * Usa los escenarios ya capturados en `tests/Fixtures/legacy-payment-request-sql.json`
 * y ejecuta ambas consultas sobre la misma base.
 *
 *   php tools/bench_payments.php
 */
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Queries\PaymentRequestFilters;
use App\Queries\PaymentRequestQuery;
use Illuminate\Support\Facades\DB;

$fixture = __DIR__.'/../tests/Fixtures/legacy-payment-request-sql.json';
$escenarios = json_decode(file_get_contents($fixture), true);

$grupo = function (array $filtros): string {
    $columnas = $filtros['groupBy'] ?? ['payment_request.request_id'];

    return match ($columnas[0]) {
        'payment_request.client_id' => 'client',
        'payment_request.provider_id' => 'provider',
        'payment_request.type' => 'type',
        default => 'request',
    };
};

printf("%-22s %12s %12s %8s  %s\n", 'escenario', 'Yii2', 'Laravel', 'veces', 'filas');
printf("%s\n", str_repeat('-', 70));

$totalViejo = 0;
$totalNuevo = 0;

foreach ($escenarios as $escenario) {
    $agrupacion = $grupo($escenario['filters']);

    $filtros = PaymentRequestFilters::make(collect($escenario['filters'])->except('groupBy')->all());
    $filtros->groupBy = $agrupacion;

    if (array_key_exists('paid', $escenario['filters'])) {
        $filtros->paid = (int) $escenario['filters']['paid'];
    }

    try {
        $inicio = microtime(true);
        $viejas = DB::select($escenario['sql']);
        $msViejo = (microtime(true) - $inicio) * 1000;
    } catch (Throwable $e) {
        printf("%-22s %12s %12s %8s  %s\n", $escenario['name'], 'ERROR', '—', '—', 'defecto del original');

        continue;
    }

    $inicio = microtime(true);
    $nuevas = PaymentRequestQuery::make($filtros)->get();
    $msNuevo = (microtime(true) - $inicio) * 1000;

    $totalViejo += $msViejo;
    $totalNuevo += $msNuevo;

    printf(
        "%-22s %10.0f ms %10.0f ms %7.1fx  %d\n",
        $escenario['name'],
        $msViejo,
        $msNuevo,
        $msNuevo > 0 ? $msViejo / $msNuevo : 0,
        count($viejas),
    );
}

printf("%s\n", str_repeat('-', 70));
printf("%-22s %10.0f ms %10.0f ms %7.1fx\n", 'TOTAL', $totalViejo, $totalNuevo, $totalNuevo > 0 ? $totalViejo / $totalNuevo : 0);
