<?php
// Evitar acceso directo no autorizado
// if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_SECRET'])) {
//     http_response_code(403);
//     die('Forbidden');
// }

// /modules/amazonfrik/cron/notify_shipments.php
include(dirname(__FILE__).'/../../../config/config.inc.php');
include(dirname(__FILE__).'/../amazonfrik.php');

$module = new Amazonfrik();
$module->processPendingShipments();