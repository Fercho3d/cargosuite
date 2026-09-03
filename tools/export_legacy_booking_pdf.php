<?php

/**
 * Captura el HTML REAL de la confirmación de booking que produce
 * `Booking::generateBokingConfirmation()` en Yii2, para la prueba de paridad.
 *
 * Solo LEE de la base local y no manda ningún correo.
 *
 * Uso: php tools/export_legacy_booking_pdf.php
 */
use app\models\Booking;
use yii\console\Application;

// El original está escrito para PHP 7: en PHP 8.5 emite deprecations y warnings
// que YII_DEBUG escalaría a excepción. Se baja el nivel igual que `web/index.php`.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_WARNING & ~E_USER_WARNING & ~E_NOTICE & ~E_USER_NOTICE);
ini_set('display_errors', '1');
ini_set('memory_limit', '2G');

$root = getenv('FREGO_YII_ROOT') ?: '/opt/homebrew/var/www/frego';
$out = __DIR__.'/../tests/Fixtures/legacy-booking-confirmation.json';

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

require $root.'/vendor/autoload.php';
require $root.'/vendor/yiisoft/yii2/Yii.php';

$config = require $root.'/config/console.php';
$config['aliases']['@webfolder'] = $root;
new Application($config);

$db = Yii::$app->db;

// Bookings con carga y con cliente: los que de verdad generan confirmación.
// Se toman de los dos modos para capturar también la tabla de la cotización.
$ids = $db->createCommand('
    SELECT b.booking_id
    FROM booking b
    JOIN containers c ON c.booking = b.booking_id
    WHERE b.client IS NOT NULL
    GROUP BY b.booking_id
    ORDER BY b.booking_id DESC
    LIMIT 10
')->queryColumn();

$cotizacion = $db->createCommand('
    SELECT b.booking_id
    FROM booking b
    JOIN containers c ON c.booking = b.booking_id
    WHERE b.client IS NOT NULL AND b.mode = 9
    GROUP BY b.booking_id
    ORDER BY b.booking_id DESC
    LIMIT 2
')->queryColumn();

$export = [];

foreach (array_unique(array_merge($ids, $cotizacion)) as $id) {
    $booking = Booking::findOne($id);

    if ($booking === null) {
        continue;
    }

    $export[] = [
        'booking_id' => (int) $id,
        'mode' => (int) $booking->mode,
        'html' => $booking->generateBokingConfirmation(),
        'css' => $booking->generateCss(),
    ];

    echo "ok  $id\n";
}

@mkdir(dirname($out), 0775, true);
file_put_contents($out, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo count($export), " bookings capturados en $out\n";
