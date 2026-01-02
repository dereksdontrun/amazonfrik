<?php
// Evitar acceso directo no autorizado
// if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_SECRET'])) {
//     http_response_code(403);
//     die('Forbidden');
// }

// https://lafrikileria.com/modules/amazonfrik/cron/notify_shipments.php?token=zpM1cUrzRI7rdewgjW8lyJSg6Aam0tT0

//proteger el endpoint con token
// Ej: https://.../modules/amazonfrik/cron/notify_shipments.php?token=zpM1cUrzRI7rdewgjW8lyJSg6Aam0tT0
$expected = 'zpM1cUrzRI7rdewgjW8lyJSg6Aam0tT0';
if (php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($token !== $expected) {
        header('HTTP/1.1 403 Forbidden');
        die('Forbidden');
    }
}

include(dirname(__FILE__).'/../../../config/config.inc.php');
include(dirname(__FILE__).'/../amazonfrik.php');

$module = new Amazonfrik();
// Ejecuta y devuelve cuántos ha procesado (intentados)
$count = (int) $module->processPendingShipments();

echo 'Procesados (intentados) ' . (int)$count . ' pedidos.';