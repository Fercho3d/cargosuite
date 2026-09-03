<?php

/**
 * Genera el layout CFDI REAL que produce `models/CFDI.php` de Yii2 para unas
 * facturas de la base local, y lo guarda como fixture de la prueba de paridad.
 *
 * Solo LEE del proyecto Frego y NO habla con el PAC.
 */
use app\models\CFDI;
use app\models\Transaction;
use app\models\TransactionSearch;
use yii\console\Application;

error_reporting(E_ERROR);
ini_set('display_errors', '1');
ini_set('memory_limit', '2G');

$root = getenv('FREGO_YII_ROOT') ?: '/opt/homebrew/var/www/frego';
$out = __DIR__.'/../tests/Fixtures/legacy-cfdi-layout.json';

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

require $root.'/vendor/autoload.php';
require $root.'/vendor/yiisoft/yii2/Yii.php';

$config = require $root.'/config/console.php';
$config['aliases']['@webfolder'] = $root;
new Application($config);

$db = Yii::$app->db;

// Facturas al cliente con conceptos y con cliente capturado: las que se timbran.
$ids = $db->createCommand("
    SELECT t.transc_id
    FROM transaction t
    JOIN charge c ON c.transaction = t.transc_id
    JOIN client cl ON cl.client_id = t.customer
    JOIN booking b ON b.booking_id = t.booking
    WHERE t.tran_type = 0 AND b.mode = 10 AND cl.rfc IS NOT NULL AND cl.rfc <> ''
    GROUP BY t.transc_id
    ORDER BY t.transc_id DESC
    LIMIT 12
")->queryColumn();

$export = [];

foreach ($ids as $id) {
    // El generador espera el modelo con las columnas calculadas del buscador
    // (tipo de cambio, número de booking, totales), igual que al timbrar.
    $search = new TransactionSearch;
    $search->tran_in = [$id];
    $search->pagination = false;
    $fila = $search->search([])->models[0] ?? null;

    if ($fila === null) {
        continue;
    }

    $model = Transaction::findOne($id);

    foreach (['exchange_value', 'booking_number', 'total_amount', 'amount_original'] as $extra) {
        $model->{$extra} = $fila->{$extra};
    }

    $cfdi = new CFDI;
    $layout = $cfdi->generar($model->getEmisorRfc(), $model);

    if (! $layout) {
        continue;
    }

    $export[] = ['transc_id' => (int) $id, 'layout' => $layout];

    echo "ok  $id\n";
}

@mkdir(dirname($out), 0775, true);
file_put_contents($out, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "\n".count($export)." layouts -> $out\n";
