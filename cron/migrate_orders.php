<?php
// migración incial de los pedidos sin confirmar que ya estén en prestashop
include(dirname(__FILE__).'/../../../config/config.inc.php');
include(dirname(__FILE__).'/../amazonfrik.php');

$module = new Amazonfrik();
$count = $module->migrateExistingAmazonOrders();
echo "Migrados {$count} pedidos con todos los datos.";