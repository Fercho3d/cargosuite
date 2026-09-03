<?php

/**
 * Genera el SQL REAL que produce TransactionSearch (Yii2) para una matriz de
 * escenarios, y lo guarda como fixture para las pruebas de paridad de Laravel.
 *
 * Solo LEE del proyecto Frego.
 */
use app\models\TransactionSearch;
use yii\console\Application;

error_reporting(E_ERROR);
ini_set('display_errors', '1');
ini_set('memory_limit', '2G');

$root = getenv('FREGO_YII_ROOT') ?: '/opt/homebrew/var/www/frego';
$out = __DIR__.'/../tests/Fixtures/legacy-transaction-sql.json';

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

require $root.'/vendor/autoload.php';
require $root.'/vendor/yiisoft/yii2/Yii.php';

$config = require $root.'/config/console.php';
$config['aliases']['@webfolder'] = $root;
new Application($config);

$_GET['debug'] = 0;

// Datos reales sobre los que apoyar los escenarios.
$db = Yii::$app->db;
$booking = (int) $db->createCommand(
    'SELECT booking FROM transaction WHERE booking IS NOT NULL GROUP BY booking ORDER BY COUNT(*) DESC LIMIT 1'
)->queryScalar();
$bookingIds = $db->createCommand(
    'SELECT booking FROM transaction WHERE booking IS NOT NULL GROUP BY booking ORDER BY COUNT(*) DESC LIMIT 8'
)->queryColumn();
$requestId = (int) $db->createCommand(
    'SELECT request_id FROM payments_by_transaction GROUP BY request_id ORDER BY COUNT(*) DESC LIMIT 1'
)->queryScalar();
$sealIds = $db->createCommand(
    "SELECT transc_id FROM transaction WHERE tran_type = 0 AND (seal IS NULL OR seal = '') ORDER BY transc_id DESC LIMIT 25"
)->queryColumn();
$tranIds = $db->createCommand(
    'SELECT transc_id FROM transaction ORDER BY transc_id DESC LIMIT 40'
)->queryColumn();

$scenarios = [
    // --- Las cuatro pantallas principales, tal cual las arma el controlador ---
    'invoice' => ['type' => [0]],
    'bill' => ['type' => [1, 2], 'noNegative' => false, 'paymentMode' => true],
    'all' => [],
    'index_booking' => ['booking' => $booking, 'cancelled' => 0, 'groupBy' => 'transc_id'],

    // --- Filtros de la rejilla ---
    'invoice_fechas' => ['type' => [0], 'dates' => '01/01/2025 - 30/06/2025'],
    'bill_fechas' => ['type' => [1, 2], 'paymentMode' => true, 'dates' => '01/01/2025 - 30/06/2025'],
    'invoice_compania' => ['type' => [0], 'company_id' => 2],
    'invoice_divisa_usd' => ['type' => [0], 'account' => 2],
    'invoice_num_like' => ['type' => [0], 'tran_number' => 'F-14'],
    'invoice_booking_like' => ['type' => [0], 'booking_number' => 'MEX'],
    'invoice_applied_to' => ['type' => [0], 'appliedTo' => 'PRO'],
    'invoice_con_sello' => ['type' => [0], 'seal' => 'A'],
    'bookings_in' => ['booking_in' => $bookingIds],
    'fechas_booking' => ['type' => [0], 'dates_booking' => '01/01/2025 - 31/12/2025'],

    // --- Estado de pago (filtros con HAVING) ---
    'bill_unpaid' => ['type' => [1, 2], 'paymentMode' => true, 'paid' => 0],
    'bill_pagado' => ['type' => [1, 2], 'paymentMode' => true, 'paid' => 1],
    'bill_parcial' => ['type' => [1, 2], 'paymentMode' => true, 'paid' => 2],
    'bill_solo_pendientes' => ['type' => [1, 2], 'paymentMode' => true, 'onlyUndpaid' => true],

    // --- Cancelaciones ---
    'all_con_canceladas' => ['showCancelled' => 1],
    'all_solo_canceladas' => ['showCancelled' => 2],

    // --- Reportes agrupados ---
    'group_booking' => ['groupBy' => 'booking'],
    'group_customer' => ['groupBy' => 'customer'],
    'group_vendor' => ['groupBy' => 'vendor'],

    // --- Modos especiales ---
    'timbrar_seleccion' => ['transc_id_in' => $sealIds, 'noNegative' => true],
    'monto_a_pagar' => ['tran_in' => $tranIds, 'noExchange' => true, 'noNegative' => false, 'paymentMode' => true, 'groupBy' => 'transaction.transc_id'],
    'cotizaciones' => ['showQuatation' => true],
    'por_solicitud_pago' => ['request_id' => $requestId],
    'excluye_solicitud_pago' => ['type' => [1, 2], 'paymentMode' => true, 'notIn' => $requestId],
    'sin_conversion' => ['type' => [0], 'noExchange' => true],
    'sin_negativos' => ['type' => [1, 2], 'noNegative' => true],
    'modo_factura' => ['type' => [0], 'invoiceMode' => true],
];

$export = [];

foreach ($scenarios as $name => $props) {
    $search = new TransactionSearch;
    $search->asArray = true;
    $search->pagination = false;

    foreach ($props as $key => $value) {
        $search->{$key} = $value;
    }

    $provider = $search->search([]);

    $export[] = [
        'name' => $name,
        'filters' => $props,
        'group_by' => $search->groupBy ?: 'transc_id',
        'sql' => $provider->query->createCommand()->getRawSql(),
    ];

    echo "ok  $name\n";
}

@mkdir(dirname($out), 0775, true);
file_put_contents($out, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "\n".count($export)." escenarios -> $out\n";
