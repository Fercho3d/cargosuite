<?php

/**
 * Genera el SQL REAL que produce PaymentRequestSearch (Yii2) para una matriz de
 * escenarios, y lo guarda como fixture para las pruebas de paridad de Laravel.
 *
 * Solo LEE del proyecto Frego. Hermano de `export_legacy_sql.php`.
 */
use app\models\PaymentRequestSearch;
use yii\console\Application;

error_reporting(E_ERROR);
ini_set('display_errors', '1');
ini_set('memory_limit', '2G');

$root = getenv('FREGO_YII_ROOT') ?: '/opt/homebrew/var/www/frego';
$out = __DIR__.'/../tests/Fixtures/legacy-payment-request-sql.json';

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

require $root.'/vendor/autoload.php';
require $root.'/vendor/yiisoft/yii2/Yii.php';

$config = require $root.'/config/console.php';
$config['aliases']['@webfolder'] = $root;
new Application($config);

$db = Yii::$app->db;

$requestId = (int) $db->createCommand(
    'SELECT request_id FROM payments_by_transaction GROUP BY request_id ORDER BY COUNT(*) DESC LIMIT 1'
)->queryScalar();
$clientId = (int) $db->createCommand(
    'SELECT client_id FROM payment_request WHERE client_id IS NOT NULL GROUP BY client_id ORDER BY COUNT(*) DESC LIMIT 1'
)->queryScalar();
$providerId = (int) $db->createCommand(
    'SELECT provider_id FROM payment_request WHERE provider_id IS NOT NULL GROUP BY provider_id ORDER BY COUNT(*) DESC LIMIT 1'
)->queryScalar();
$bankId = (int) $db->createCommand(
    'SELECT bank_id FROM payment_request WHERE bank_id IS NOT NULL GROUP BY bank_id ORDER BY COUNT(*) DESC LIMIT 1'
)->queryScalar();

// Fecha de valuación: un día hábil con tipo de cambio ya registrado, para que el
// escenario no dependa de que el DOF conteste.
$fechaPago = $db->createCommand(
    'SELECT date_exchange FROM exchange WHERE account = 2 ORDER BY date_exchange DESC LIMIT 1'
)->queryScalar();
$fechaPago = date('d/m/Y', strtotime($fechaPago));

$scenarios = [
    // --- Las tres pantallas de reporte del controlador ---
    'report_by_customer' => ['groupBy' => ['payment_request.client_id'], 'type' => 1, 'paid' => 1],
    'report_general' => ['groupBy' => ['payment_request.type'], 'paid' => 1],
    'report_by_vendor' => ['groupBy' => ['payment_request.provider_id', 'payment_request.currency_id'], 'paid' => 1, 'type' => 2, 'noNegative' => true],

    // --- Detalles (una fila por solicitud) ---
    'general_detail' => ['groupBy' => ['payment_request.request_id'], 'paid' => 1],
    'customer_detail' => ['groupBy' => ['payment_request.request_id'], 'type' => 1, 'paid' => 1],
    'vendor_detail' => ['groupBy' => ['payment_request.request_id'], 'type' => 2, 'paid' => 1],

    // --- Filtros ---
    'por_solicitud' => ['groupBy' => ['payment_request.request_id'], 'request_id' => $requestId],
    'por_cliente' => ['groupBy' => ['payment_request.request_id'], 'client_id' => $clientId],
    'por_proveedor' => ['groupBy' => ['payment_request.request_id'], 'provider_id' => $providerId],
    'por_banco' => ['groupBy' => ['payment_request.request_id'], 'bank_id' => $bankId],
    'por_fechas' => ['groupBy' => ['payment_request.request_id'], 'dates' => '01/01/2025 - 31/12/2025'],
    'sin_pagar' => ['groupBy' => ['payment_request.request_id'], 'paid' => 0],

    // --- Modos que cambian la aritmética ---
    'con_fecha_de_pago' => ['groupBy' => ['payment_request.request_id'], 'paid' => 1, 'date_pay' => $fechaPago],
    'sin_conversion' => ['groupBy' => ['payment_request.request_id'], 'noExchange' => true],
    'sin_negativos' => ['groupBy' => ['payment_request.request_id'], 'noNegative' => true],
];

$export = [];

foreach ($scenarios as $name => $props) {
    $search = new PaymentRequestSearch;

    foreach ($props as $key => $value) {
        $search->{$key} = $value;
    }

    $provider = $search->search([]);

    $export[] = [
        'name' => $name,
        'filters' => $props,
        'sql' => $provider->query->createCommand()->getRawSql(),
    ];

    echo "ok  $name\n";
}

@mkdir(dirname($out), 0775, true);
file_put_contents($out, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "\n".count($export)." escenarios -> $out\n";
