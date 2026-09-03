<?php

/**
 * Mide el tiempo REAL de las pantallas del módulo Transactions en Yii2,
 * ejecutando exactamente lo que hace cada acción del controlador
 * (incluidos los pases extra del profit summary de `invoice`).
 * Solo lee; no modifica Frego.
 */
use app\models\TransactionSearch;
use yii\console\Application;

error_reporting(E_ERROR);
ini_set('display_errors', '1');
ini_set('memory_limit', '2G');

$root = getenv('FREGO_YII_ROOT') ?: '/opt/homebrew/var/www/frego';

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

require $root.'/vendor/autoload.php';
require $root.'/vendor/yiisoft/yii2/Yii.php';

$config = require $root.'/config/console.php';
$config['aliases']['@webfolder'] = $root;
new Application($config);

$_GET['debug'] = 0;

$runs = (int) ($argv[1] ?? 3);

function bench($label, callable $fn, $runs)
{
    $times = [];
    $rows = 0;
    for ($i = 0; $i < $runs; $i++) {
        Yii::$app->db->close();
        Yii::$app->db->open();
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
}

// ---- invoice: grid paginado (100) + los 3 pases del profit summary ----
bench('invoice (grid, 100 filas)', function () {
    $s = new TransactionSearch;
    $s->type = [0];
    $s->asArray = true;
    $dp = $s->search([]);
    $dp->prepare();
    $dp->getTotalCount();

    return count($dp->getModels());
}, $runs);

bench('invoice (profit summary x3)', function () {
    $n = 0;

    $inv = new TransactionSearch;
    $inv->type = [0];
    $inv->asArray = true;
    $inv->pagination = false;
    $bk = [];
    foreach ($inv->search([])->getModels() as $r) {
        $bk[$r['booking_id']] = true;
    }
    $ids = array_keys($bk);
    $n += count($ids);

    $invAll = new TransactionSearch;
    $invAll->type = [0];
    $invAll->booking_in = $ids;
    $invAll->asArray = true;
    $invAll->pagination = false;
    $n += count($invAll->search([])->getModels());

    $cost = new TransactionSearch;
    $cost->type = [1, 2];
    $cost->booking_in = $ids;
    $cost->asArray = true;
    $cost->pagination = false;
    $n += count($cost->search([])->getModels());

    return $n;
}, $runs);

// ---- bill ----
bench('bill (grid, 100 filas)', function () {
    $s = new TransactionSearch;
    $s->type = [1, 2];
    $s->noNegative = false;
    $s->paymentMode = true;
    $s->asArray = true;
    $dp = $s->search([]);
    $dp->prepare();
    $dp->getTotalCount();

    return count($dp->getModels());
}, $runs);

// ---- all ----
bench('all (grid, 100 filas)', function () {
    $s = new TransactionSearch;
    $dp = $s->search([]);
    $dp->prepare();
    $dp->getTotalCount();

    return count($dp->getModels());
}, $runs);

// ---- index por booking (sin paginación) ----
$booking = (int) Yii::$app->db->createCommand(
    'SELECT booking FROM transaction WHERE booking IS NOT NULL GROUP BY booking ORDER BY COUNT(*) DESC LIMIT 1'
)->queryScalar();
echo "booking de prueba: $booking\n";

bench('index (1 booking)', function () use ($booking) {
    $s = new TransactionSearch;
    $s->booking = $booking;
    $s->cancelled = 0;
    $s->pagination = false;
    $s->asArray = true;
    $s->groupBy = 'transc_id';

    return count($s->search([])->getModels());
}, $runs);

// ---- filtro "Unpaid" (HAVING) ----
bench('bill + filtro Unpaid', function () {
    $s = new TransactionSearch;
    $s->type = [1, 2];
    $s->paymentMode = true;
    $s->asArray = true;
    $s->paid = 0;
    $dp = $s->search([]);
    $dp->prepare();
    $dp->getTotalCount();

    return count($dp->getModels());
}, $runs);
