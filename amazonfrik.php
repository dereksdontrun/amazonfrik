<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/AmazonFrikOrder.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';
require_once __DIR__ . '/classes/AmazonShipmentNotifier.php';
require_once __DIR__ . '/classes/AmazonOrderImporter.php';

class Amazonfrik extends Module
{
    //estados que consideramos que valen para ya confirmar pedido (enviado y etiquetados)
    // Enviado => 4
    // Etiquetado Correos => 29
    // Etiquetado GLS => 64
    // Etiquetado Spring => 65
    // Etiquetado UPS => 85
    // Etiquetado MRW => 66
    // Etiquetado DHL => 68    
    public $shipped_states = array(4, 29, 64, 65, 85);

    // Configuración
    // === MAPA DE MARKETPLACES ===
    private $marketplaces = [
        'A1RKKUPIHCS9HS' => ['iso' => 'ES', 'origin' => 'Amazon.es', 'lang' => 'ES'],
        'A1F83G8C2ARO7P' => ['iso' => 'GB', 'origin' => 'Amazon.co.uk', 'lang' => 'EN'],
        'A13V1IB3VIYZZH' => ['iso' => 'FR', 'origin' => 'Amazon.fr', 'lang' => 'FR'],
        'APJ6JRA9NG5V4' => ['iso' => 'IT', 'origin' => 'Amazon.it', 'lang' => 'IT'],
        'A1PA6795UKMFR9' => ['iso' => 'DE', 'origin' => 'Amazon.de', 'lang' => 'DE'],
        'A1805IZSGTT6HS' => ['iso' => 'NL', 'origin' => 'Amazon.nl', 'lang' => 'NL'],
        'AMEN7PMS3EDWL' => ['iso' => 'BE', 'origin' => 'Amazon.com.be', 'lang' => 'FR'],
    ];

    // IVA por país (ajusta según tus necesidades)
    private $tax_rates = [
        'ES' => '21.000',
        'GB' => '20.000',
        'FR' => '20.000',
        'IT' => '22.000',
        'DE' => '19.000',
        'NL' => '21.000',
        'BE' => '21.000'
    ];

    private $carrier_mapping = [
        // id_carrier_reference => [código Amazon, shipping method, nombre prestashop]
        516 => ['CORREOS', 'Paq Estandar', 'CORREOS - Entrega Domicílio'],
        301 => ['GLS', 'Courier', 'GLS Express 24h'],
        368 => ['GLS', 'Economy', 'Entrega Domicílio'],
        467 => ['GLS', 'Euro Business Parcel', 'GLS - EuroBusiness'],
        // 629 => ['GLS', 'ECO envío - Recoge donde quieras'],
        630 => ['GLS', 'BusinessParcel', 'GLS - BusinessParcel'],
        // 790 => ['GLS', 'GLS - Parcelshop Europa'],
        610 => ['UPS', 'Standard', 'UPS'],
        322 => ['SPRING', 'Tracked', 'Spring GDS - TRACKED'],
        323 => ['SPRING', 'Signatured', 'Spring GDS - SIGNATURED'],
    ];

    /** @var array|null */
    public $lastShipmentResult = null;

    public function __construct()
    {
        $this->name = 'amazonfrik';
        $this->tab = 'marketplace';
        $this->version = '1.0.0';
        $this->author = 'Sergio';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Amazon Frikilería');
        $this->description = $this->l('Gestión de pedidos y productos de Amazon');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Registrar hook para detectar cambios de estado o cambios en order carrier
        if (
            !$this->registerHook('actionOrderStatusPostUpdate') ||
            !$this->registerHook('actionObjectOrderCarrierUpdateAfter')
        ) {
            return false;
        }

        // Hook custom
        $hook_name = 'actionAmazonOrderDataReady';
        if (!Hook::getIdByName($hook_name)) {
            $new_hook = new Hook();
            $new_hook->name = $hook_name;
            $new_hook->title = 'Amazon Order Data Ready';
            $new_hook->description = 'Se llama desde cambiatransportistaspringamazon cuando un pedido Amazon ya tiene sus datos listos en lafrips_orders';
            $new_hook->position = 0;
            if (!$new_hook->add())
                return false;
        }

        if (!$this->registerHook($hook_name))
            return false;

        if (!$this->installTab('AdminAmazonFrikOrders', 'AdminOrders', $this->name, 'Pedidos Amazon')) {
            return false;
        }

        return true;

        // Crear tabla de control
        // return $this->createTables();

        /*  SQL PARA INSTALAR HOOK SIN REINICIALIZAR MODULO
        -- 1) Crear el hook si no existe
            INSERT IGNORE INTO `lafrips_hook` (`name`, `title`, `description`, `position`, `live_edit`)
            VALUES (
            'actionAmazonOrderDataReady',
            'Amazon Order Data Ready',
            'Se llama desde cambiatransportistaspringamazon cuando un pedido Amazon ya tiene sus datos listos en lafrips_orders',
            0,
            0
            );

        -- 2) Asociar el módulo al hook
            INSERT IGNORE INTO `lafrips_hook_module` (`id_module`, `id_hook`, `position`)
            SELECT m.id_module, h.id_hook, 0
            FROM `lafrips_module` m
            JOIN `lafrips_hook` h ON h.name='actionAmazonOrderDataReady'
            WHERE m.name='amazonfrik';

        */
    }

    protected function installTab($classname = false, $parent = false, $module = false, $displayname = false)
    {
        if (!$classname)
            return true;

        $tab = new Tab();
        $tab->class_name = $classname;
        if ($parent)
            if (!is_int($parent))
                $tab->id_parent = (int) Tab::getIdFromClassName($parent);
            else
                $tab->id_parent = (int) $parent;
        if (!$module)
            $module = $this->name;
        $tab->module = $module;
        $tab->active = true;
        if (!$displayname)
            $displayname = $this->displayName;
        $tab->name[(int) (Configuration::get('PS_LANG_DEFAULT'))] = $displayname;

        if (!$tab->add())
            return false;

        return true;
    }

    /**
     * Elimina la pestaña al desinstalar el módulo
     */
    protected function unInstallTab($classname = false)
    {
        if (!$classname)
            return true;

        $idTab = Tab::getIdFromClassName($classname);
        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
        }
        return true;
    }

    public function uninstall()
    {
        $this->unInstallTab('AdminAmazonFrikOrders');

        // $this->dropTables();

        return parent::uninstall();
    }

    // private function createTables()
    // {
    //     $sql = 'CREATE TABLE IF NOT EXISTS `lafrips_amazonfrik_orders` (
    //         `id_amazonfrik_orders` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    //         `amazon_order_id` VARCHAR(64) NOT NULL COMMENT 'Order ID from Amazon',
    //         `marketplace` VARCHAR(64) NOT NULL COMMENT 'Ej: Amazon.es, Amazon.co.uk',
    //         `marketplace_id` VARCHAR(30) NOT NULL COMMENT 'Amazon marketplace ID (e.g., A1F83G8C2ARO7P)',
    //         `id_order` INT(11) NULL DEFAULT NULL COMMENT 'PrestaShop order ID (from ps_orders)',
    //         `order_status` VARCHAR(20) NOT NULL DEFAULT 'imported' COMMENT 'imported, shipped, error, cancelled, etc.',
    //         `shipment_notified` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '1 if shipment confirmation was sent to Amazon',
    //         `id_order_status_when_confirmed` INT(10) NULL DEFAULT NULL COMMENT 'Estado de pedido de PrestaShop cuando se confirmo envio',
    //         `tracking_number` VARCHAR(100) NULL DEFAULT NULL,
    //         `carrier_code` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Amazon carrier code (e.g., CORREOS, UPS, etc.)',
    //         `shipping_method` VARCHAR(100) NULL DEFAULT NULL,
    //         `shipping_deadline` DATETIME NULL DEFAULT NULL,
    //         `purchase_date` DATETIME NULL DEFAULT NULL,
    //         `ship_service_level` VARCHAR(50) NULL DEFAULT NULL COMMENT 'ShipServiceLevel from Amazon',
    //         `order_total` DECIMAL(20,6) NULL DEFAULT NULL COMMENT 'OrderTotal.Amount',
    //         `currency` VARCHAR(10) NULL DEFAULT NULL COMMENT 'OrderTotal.CurrencyCode',
    //         `ship_date` DATETIME NULL DEFAULT NULL,
    //         `last_notification_attempt` DATETIME NULL DEFAULT NULL,
    //         `notification_error` TEXT NULL DEFAULT NULL,
    //         `date_add` DATETIME NOT NULL,
    //         `date_upd` DATETIME NOT NULL,
    //         PRIMARY KEY (`id_amazonfrik_orders`),
    //         UNIQUE KEY `uniq_amazon_order` (`amazon_order_id`, `marketplace_id`),
    //         KEY `idx_id_order` (`id_order`),
    //         KEY `idx_pending` (`shipment_notified`, `order_status`, `last_notification_attempt`),
    //         KEY `idx_marketplace` (`marketplace_id`, `amazon_order_id`),
    //         KEY `idx_tracking` (`tracking_number`)
    //         ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';        

    //     if (!Db::getInstance()->execute($sql)) {
    //         return false;
    //     }

    //     return true;

    // CREATE TABLE IF NOT EXISTS `lafrips_amazonfrik_order_detail` (
    // `id_amazonfrik_order_detail` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    // `id_amazonfrik_orders` INT(11) UNSIGNED NOT NULL,

    // -- Clave para confirmar envío
    // `amazon_order_item_id` VARCHAR(50) NOT NULL COMMENT 'OrderItemId (obligatorio para /shipment)',

    // -- SKU y ASIN
    // `asin` VARCHAR(20) NULL DEFAULT NULL COMMENT 'ASIN de Amazon (ej. B004EFUQ38)',
    // `seller_sku` VARCHAR(100) NULL DEFAULT NULL COMMENT 'SellerSKU = SKU en PrestaShop',

    // -- PrestaShop  
    // `id_product` INT(11) UNSIGNED NULL DEFAULT NULL,
    // `id_product_attribute` INT(11) UNSIGNED NULL DEFAULT NULL,

    // -- Otros datos útiles
    // `product_name` VARCHAR(255) NULL DEFAULT NULL,
    // `quantity` INT(11) UNSIGNED NOT NULL,
    // `item_price` DECIMAL(20,6) NULL DEFAULT NULL,
    // `item_tax` DECIMAL(20,6) NULL DEFAULT NULL,

    // PRIMARY KEY (`id_amazonfrik_order_detail`),
    // UNIQUE KEY `uniq_order_item` (`id_amazonfrik_orders`, `amazon_order_item_id`),
    // KEY `idx_id_amazonfrik_orders` (`id_amazonfrik_orders`),
    // KEY `idx_amazon_order_item_id` (`amazon_order_item_id`),
    // KEY `idx_asin` (`asin`),
    // KEY `idx_seller_sku` (`seller_sku`),
    // KEY `idx_id_product` (`id_product`),
    // KEY `idx_order_sku` (`id_amazonfrik_orders`, `seller_sku`),
    // CONSTRAINT `fk_amazonfrik_order` 
    //     FOREIGN KEY (`id_amazonfrik_orders`) 
    //     REFERENCES `lafrips_amazonfrik_orders` (`id_amazonfrik_orders`) 
    //     ON DELETE CASCADE
    // ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    // }

    private function dropTables()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'amazonfrik_orders`');
    }


    /**
     * Obtiene la información del marketplace a partir del ID de Amazon
     */
    public function getMarketplaceInfoById($marketplace_id)
    {
        return isset($this->marketplaces[$marketplace_id]) ? $this->marketplaces[$marketplace_id] : null;
    }

    /**
     * Obtiene el ID de Amazon a partir del nombre "origin" (ej. "Amazon.es")
     */
    public function getMarketplaceIdByOrigin($origin)
    {
        foreach ($this->marketplaces as $id => $info) {
            if ($info['origin'] === $origin) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Obtiene el nombre "origin" a partir del ID
     */
    public function getMarketplaceOriginById($marketplace_id)
    {
        $info = $this->getMarketplaceInfoById($marketplace_id);
        return $info ? $info['origin'] : null;
    }

    //como a veces el tracking aún no existe cuando el pedido cambia a etiquetado, ponemos otro hook a update de order carrier, ahí si que sabremos si hay tracking
    public function hookActionObjectOrderCarrierUpdateAfter($params)
    {
        $this->handleOrderCarrierTrackingEvent($params, 'updateAfter');
    }

    protected function handleOrderCarrierTrackingEvent($params, $source = '')
    {
        if (empty($params['object']) || !is_object($params['object'])) {
            return;
        }

        /** @var OrderCarrier $oc */
        $oc = $params['object'];

        $id_order = (int) $oc->id_order;
        $tracking = isset($oc->tracking_number) ? trim((string) $oc->tracking_number) : '';

        // Log (para depurar si entra o no)
        $logger = new LoggerFrik(
            _PS_MODULE_DIR_ . 'amazonfrik/logs/ordercarrier_hook_' . date('Ymd') . '.txt',
            true,
            'sergio@lafrikileria.com'
        );

        if (!$id_order) {
            return;
        }

        // Tracking inválido ,no hacemos nada 
        if (!$this->isValidTracking($tracking)) {
            $logger->log("{$source}: id_order={$id_order} tracking inválido: \"" . $tracking . "\"", 'DEBUG', false);
            return;
        }

        // Solo si el pedido está en un estado "shipped_states" (etiquetados/enviado)
        $current_state = (int) Db::getInstance()->getValue('
            SELECT current_state
            FROM `' . _DB_PREFIX_ . 'orders`
            WHERE id_order = ' . (int) $id_order . '
        ');

        if (!in_array($current_state, array_map('intval', $this->shipped_states), true)) {
            return;
        }

        // Buscar si es un pedido Amazon pendiente
        $row = Db::getInstance()->getRow('
            SELECT id_amazonfrik_orders, tracking_number, shipment_notified
            FROM `' . _DB_PREFIX_ . 'amazonfrik_orders`
            WHERE id_order = ' . (int) $id_order);

        if (!$row || (int) $row['shipment_notified'] === 1) {
            $logger->log("{$source}: id_order={$id_order} no está en amazonfrik_orders o ya notificado.", 'DEBUG', false);
            return;
        }

        $id_af = (int) $row['id_amazonfrik_orders'];

        // Guardar tracking en amazonfrik_orders si estaba vacío o distinto
        $af_tracking = trim((string) $row['tracking_number']);
        if (!$this->isValidTracking($af_tracking) || $af_tracking !== $tracking) {
            Db::getInstance()->update(
                'amazonfrik_orders',
                [
                    'tracking_number' => pSQL($tracking),
                    'date_upd' => date('Y-m-d H:i:s'),
                ],
                'id_amazonfrik_orders=' . (int) $id_af
            );

            $logger->log("{$source}: tracking guardado en amazonfrik_orders. id_af={$id_af} tracking={$tracking}", 'INFO', false);
        }

        // Intentar confirmación inmediata        
        try {
            $ok = (bool) $this->markOrderAsShippedInAmazon(
                $id_af,
                $tracking,
                date('Y-m-d H:i:s'),
                $current_state
            );
        } catch (Exception $e) {
            $this->appendNotificationError($id_af, 'OC_EXCEPTION', "Hook OrderCarrier ({$source}): " . $e->getMessage());
            $logger->log("{$source}: EXCEPTION id_af={$id_af} " . $e->getMessage(), 'ERROR', false);
            return;
        }

        // Opcional: si falla, deja rastro específico del “tracking-event”
        if (!$ok) {
            $res = $this->getLastShipmentResult();
            $code = !empty($res['code']) ? $res['code'] : 'UNKNOWN';
            $msg = !empty($res['message']) ? $res['message'] : 'Fallo sin mensaje';
            $this->appendNotificationError($id_af, 'OC_' . $code, 'Hook OrderCarrier (' . $source . '): ' . $msg);
            $logger->log("{$source}: KO id_af={$id_af} code={$code} msg={$msg}", 'ERROR', false);
        } else {
            $logger->log("{$source}: OK id_af={$id_af} confirmado con tracking={$tracking}", 'INFO', false);
        }
    }

    // Hook que se ejecuta al cambiar el estado de un pedido
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!isset($params['id_order']) || !isset($params['newOrderStatus'])) {
            return;
        }

        $id_order = (int) $params['id_order'];
        $new_status = $params['newOrderStatus'];

        if (!in_array((int) $new_status->id, $this->shipped_states)) {
            return;
        }

        $id_order_status = (int) $new_status->id;

        $sql = 'SELECT id_amazonfrik_orders FROM `' . _DB_PREFIX_ . 'amazonfrik_orders`
        WHERE `id_order` = ' . (int) $id_order . '
        AND `shipment_notified` = 0';

        $id_amazonfrik_orders = Db::getInstance()->getValue($sql);

        if (!$id_amazonfrik_orders) {
            return;
        }

        // Obtener tracking, si no tiene no continuamos. En el caso de Correos por ejemplo, primero se pasa a Etiqeutado y luego se otiene el tracking, con lo que "esperaría" a Enviado      
        $id_order_carrier = (int) Db::getInstance()->getValue('
            SELECT id_order_carrier
            FROM `' . _DB_PREFIX_ . 'order_carrier`
            WHERE id_order = ' . (int) $id_order . '
            ORDER BY id_order_carrier DESC            
        ');

        if (!$id_order_carrier) {
            $this->appendNotificationError($id_amazonfrik_orders, 'NO_ORDER_CARRIER', 'Cambio de estado a enviado/etiquetado pero sin entrada en order_carrier');
            return;
        }

        $order_carrier = new OrderCarrier($id_order_carrier);
        $tracking_number = $order_carrier->tracking_number ?: null;

        if (!$this->isValidTracking($tracking_number)) {
            $this->appendNotificationError(
                $id_amazonfrik_orders,
                'INVALID_TRACKING',
                'Cambio de estado a enviado/etiquetado pero tracking inválido: "' . $tracking_number . '"'
            );
            return;
        }

        //antes de intentar la confirmación, almacenamos el tracking
        Db::getInstance()->update(
            'amazonfrik_orders',
            [
                'tracking_number' => pSQL($tracking_number),
                'date_upd' => date('Y-m-d H:i:s')
            ],
            'id_amazonfrik_orders=' . (int) $id_amazonfrik_orders
        );

        $this->markOrderAsShippedInAmazon(
            (int) $id_amazonfrik_orders,
            $tracking_number,
            date('Y-m-d H:i:s'),
            $id_order_status
        );
    }

    /**
     * Hook personalizado: se ejecuta cuando un pedido de Amazon está listo
     * con todos sus datos en ps_orders (ext_*)
     *
     * @param array $params
     *  - id_order
     *  - amazon_order_id
     *  - marketplace (ej. "Amazon.es")
     *  - shipping_deadline (opcional)
     *  - purchase_date
     */
    //24/12/2025 Para que este módulo pueda recoger los nuevos pedidos mientras nos dan permisos y puedo usar la API, metemos aquí un hook personalizado que se dispara en el módulo cambiatransportistaspringamazon una vez ya están los datos de Amazon en lafrips_orders (mis campos ext_origin. ext_order_id, etc). No es necesario declararlo en prestashop
    public function hookActionAmazonOrderDataReady($params)
    {
        $logger = new LoggerFrik(
            _PS_MODULE_DIR_ . 'amazonfrik/logs/order_registration_' . date('Ymd') . '.txt',
            true,
            'sergio@lafrikileria.com'
        );
        $logger->log('================================================', 'INFO', false);
        $logger->log('Hook actionAmazonOrderDataReady iniciado', 'DEBUG', false);

        // Validación estricta: todos los campos obligatorios
        if (
            empty($params['id_order'])
            || empty($params['amazon_order_id'])
            || empty($params['marketplace'])
            || empty($params['marketplace_id'])
        ) {
            $logger->log('Parámetros incompletos: ' . print_r($params, true), 'ERROR');
            return false;
        }

        $id_order = (int) $params['id_order'];
        $amazon_order_id = pSQL($params['amazon_order_id']);
        $marketplace_origin = pSQL($params['marketplace']);      // Ej: "Amazon.es"
        $marketplace_id = pSQL($params['marketplace_id']);        // Ej: "A1F83G8C2ARO7P"       
        $shipping_deadline = !empty($params['shipping_deadline']) ? $this->isoZToMysql($params['shipping_deadline']) : null;
        // $purchase_date = !empty($params['purchase_date']) ? $this->isoZToMysql($params['purchase_date']) : null;

        // Insertar en la tabla (ignorar duplicados)
        //Db::insert() devuelve bool (true/false), pero con el 4 parámtro true para que haga insert ignore no es así, puede devolver true, aunque no haya insertado nada. Así que comprobamos antes de tratar de insertar si ya existe
        $exists = (bool) Db::getInstance()->getValue('
            SELECT 1
            FROM `' . _DB_PREFIX_ . 'amazonfrik_orders`
            WHERE amazon_order_id = "' . pSQL($amazon_order_id) . '"
            AND marketplace_id = "' . pSQL($marketplace_id) . '"
        ');

        if ($exists) {
            $logger->log("Pedido ya existía: $amazon_order_id ($marketplace_id)", 'DEBUG');
            return true;
        }

        //hacemos insert sin ignore, ya sabemos que no existe
        $result = Db::getInstance()->insert(
            'amazonfrik_orders',
            array(
                'amazon_order_id' => $amazon_order_id,
                'marketplace' => $marketplace_origin,
                'marketplace_id' => $marketplace_id,
                'id_order' => $id_order,
                'order_status' => 'imported',
                'shipping_deadline' => $shipping_deadline,
                // 'purchase_date' => $purchase_date,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            false
        );

        if (!$result) {
            $logger->log("Error al insertar pedido: $amazon_order_id", 'ERROR');
            return false;
        }

        // ID insertado
        $id_amazonfrik_orders = (int) Db::getInstance()->Insert_ID();
        if (!$id_amazonfrik_orders && method_exists(Db::getInstance(), 'insert_ID')) {
            $id_amazonfrik_orders = (int) Db::getInstance()->insert_ID();
        }

        $logger->log("Pedido insertado: id_amazonfrik_orders={$id_amazonfrik_orders} | id_order={$id_order} | amazon={$amazon_order_id}", 'INFO');

        // 2) Completar datos con SP-API (order_total, currency, ship_service_level, purchase_date)
        try {
            $importer = new AmazonOrderImporter($logger);
            $order_data = $importer->getOrderDataFromAmazon($amazon_order_id);

            if (is_array($order_data)) {
                // Si purchase_date no venía por params, rellenarla desde la API
                $purchase_from_api = !empty($order_data['purchase_date'])
                    ? $this->isoZToMysql($order_data['purchase_date'])
                    : null;

                $update = array(
                    'ship_service_level' => isset($order_data['ship_service_level']) ? pSQL($order_data['ship_service_level']) : null,
                    'order_total' => isset($order_data['order_total']) ? (float) $order_data['order_total'] : null,
                    'currency' => isset($order_data['currency']) ? pSQL($order_data['currency']) : null,
                    'date_upd' => date('Y-m-d H:i:s'),
                );

                if (empty($purchase_date) && !empty($purchase_from_api)) {
                    $update['purchase_date'] = pSQL($purchase_from_api);
                }

                Db::getInstance()->update(
                    'amazonfrik_orders',
                    $update,
                    'id_amazonfrik_orders=' . (int) $id_amazonfrik_orders
                );

                $logger->log("Datos SP-API guardados: ship_service_level/order_total/currency" . (empty($purchase_date) && !empty($purchase_from_api) ? "/purchase_date" : ""), 'INFO');
            } else {
                $logger->log("WARN: SP-API no devolvió payload para completar datos del pedido $amazon_order_id", 'WARNING');
                $this->appendNotificationError($id_amazonfrik_orders, 'ORDERDATA_EMPTY', 'Alta pedido: no se pudo completar order_total/currency/ship_service_level desde Amazon');
            }
        } catch (Exception $e) {
            $logger->log("ERROR completando datos desde SP-API: " . $e->getMessage(), 'ERROR');
            $this->appendNotificationError($id_amazonfrik_orders, 'ORDERDATA_EXCEPTION', $this->truncate($e->getMessage(), 800));
        }

        // 3) Precargar items (amazonfrik_order_detail)
        try {
            $notifier = new AmazonShipmentNotifier($logger);
            $items = $notifier->getOrderItemsForShipment($amazon_order_id, $id_amazonfrik_orders);

            if (empty($items)) {
                $logger->log("WARN: No se pudieron precargar items para $amazon_order_id (id_amazonfrik_orders={$id_amazonfrik_orders})", 'WARNING');
                $this->appendNotificationError($id_amazonfrik_orders, 'NO_ORDER_ITEMS', 'Alta pedido: no se pudieron precargar items desde Amazon');
            } else {
                $logger->log("Items precargados OK: " . count($items) . " para $amazon_order_id", 'INFO');
            }
        } catch (Exception $e) {
            $logger->log("ERROR al precargar items: " . $e->getMessage(), 'ERROR');
            $this->appendNotificationError($id_amazonfrik_orders, 'ORDERITEMS_EXCEPTION', $this->truncate($e->getMessage(), 800));
        }

        return true;
    }

    protected function setShipmentResult($ok, $code, $message, array $context = [])
    {
        $this->lastShipmentResult = [
            'ok' => (bool) $ok,
            'code' => (string) $code,
            'message' => (string) $message,
            'context' => $context,
            'ts' => date('Y-m-d H:i:s'),
        ];
    }

    public function getLastShipmentResult()
    {
        return $this->lastShipmentResult;
    }

    /**
     * Confirma el envío de un pedido en Amazon SP-API y actualiza el estado local
     *
     * @param int $id_amazonfrik_orders ID en la tabla amazonfrik_orders
     * @param string|null $tracking_number Número de seguimiento
     * @param string $carrier_name Nombre del transportista en PrestaShop
     * @param string $ship_date Fecha de envío (Y-m-d H:i:s)
     * @param int|null $id_order_status ID de estado del pedido
     * @return bool Éxito de la operación
     */
    public function markOrderAsShippedInAmazon($id_amazonfrik_orders, $tracking_number, $ship_date, $id_order_status = null)
    {
        $logger = new LoggerFrik(
            _PS_MODULE_DIR_ . 'amazonfrik/logs/shipment_notify_' . date('Ymd') . '.txt',
            true,
            'sergio@lafrikileria.com'
        );

        $this->setShipmentResult(false, 'INIT', 'Iniciando proceso', [
            'id_amazonfrik_orders' => (int) $id_amazonfrik_orders
        ]);

        $logger->log('================================================', 'INFO', false);
        $logger->log("Iniciando notificación de envío para amazonfrik_order ID: {$id_amazonfrik_orders}", 'DEBUG');

        if (!$this->isValidTracking($tracking_number)) {
            $msg = 'Pedido sin tracking number o tracking inválido';
            $logger->log($msg, 'ERROR');
            $this->appendNotificationError($id_amazonfrik_orders, 'NO_TRACKING', $msg);
            $this->setShipmentResult(false, 'NO_TRACKING', $msg, [
                'id_amazonfrik_orders' => (int) $id_amazonfrik_orders
            ]);
            return false;
        }

        $sql = 'SELECT amazon_order_id, marketplace_id, id_order 
            FROM `' . _DB_PREFIX_ . 'amazonfrik_orders`
            WHERE id_amazonfrik_orders = ' . (int) $id_amazonfrik_orders;

        $row = Db::getInstance()->getRow($sql);
        if (!$row) {
            $msg = 'Pedido no encontrado en amazonfrik_orders';
            $logger->log($msg, 'ERROR');
            $this->appendNotificationError($id_amazonfrik_orders, 'NOT_FOUND', $msg);
            $this->setShipmentResult(false, 'NOT_FOUND', $msg, [
                'id_amazonfrik_orders' => (int) $id_amazonfrik_orders
            ]);
            return false;
        }

        $amazon_order_id = $row['amazon_order_id'];
        $marketplace_id = $row['marketplace_id'];
        $id_order = (int) $row['id_order'];

        $context = [
            'id_amazonfrik_orders' => (int) $id_amazonfrik_orders,
            'id_order' => (int) $id_order,
            'amazon_order_id' => (string) $amazon_order_id,
            'marketplace_id' => (string) $marketplace_id,
            'tracking_number' => (string) $tracking_number,
            'ship_date' => (string) $ship_date,
            'id_order_status' => (int) $id_order_status,
        ];

        $logger->log("Procesando pedido Prestashop {$id_order} - Amazon: {$amazon_order_id} (Marketplace: {$marketplace_id})", 'INFO');

        // 1. Obtener id_reference del carrier
        $id_carrier = (int) Db::getInstance()->getValue('
            SELECT id_carrier FROM `' . _DB_PREFIX_ . 'orders`
            WHERE id_order = ' . (int) $id_order
        );

        $id_reference = $this->getCarrierReference($id_carrier);

        if (!$id_reference) {
            $msg = "Carrier sin id_reference (id_carrier={$id_carrier})";
            $logger->log($msg, 'ERROR');
            $context['id_carrier'] = (int) $id_carrier;
            $this->appendNotificationError($id_amazonfrik_orders, 'NO_CARRIER_REFERENCE', $msg);
            $this->setShipmentResult(false, 'NO_CARRIER_REFERENCE', $msg, $context);
            return false;
        }

        $context['id_carrier'] = (int) $id_carrier;
        $context['id_reference'] = (int) $id_reference;

        // 2. Mapear a código Amazon, shipping method y nombre
        if (!isset($this->carrier_mapping[$id_reference])) {
            $msg = "Carrier reference no mapeado: {$id_reference}";
            $logger->log($msg, 'ERROR');
            $this->appendNotificationError($id_amazonfrik_orders, 'CARRIER_NOT_MAPPED', $msg);
            $this->setShipmentResult(false, 'CARRIER_NOT_MAPPED', $msg, $context);
            return false;
        }

        list($carrier_code, $shipping_method, $carrier_name) = $this->carrier_mapping[$id_reference];

        $context['carrier_code'] = (string) $carrier_code;
        $context['shipping_method'] = (string) $shipping_method;
        $context['carrier_name'] = (string) $carrier_name;

        $logger->log("Carrier mapeado {$carrier_name}: {$id_reference} → {$carrier_code} / {$shipping_method}", 'INFO');

        // 3. Obtener orderItems reales de Amazon
        $notifier = new AmazonShipmentNotifier($logger);
        $order_items = $notifier->getOrderItemsForShipment($amazon_order_id, $id_amazonfrik_orders);
        if (empty($order_items)) {
            $msg = 'No se pudieron obtener los items del pedido de Amazon';
            $logger->log($msg, 'ERROR');
            $this->appendNotificationError($id_amazonfrik_orders, 'NO_ORDER_ITEMS', $msg);
            $this->setShipmentResult(false, 'NO_ORDER_ITEMS', $msg, $context);
            return false;
        }

        $context['order_items_count'] = is_array($order_items) ? count($order_items) : 0;

        // 4. Notificar a Amazon
        //cambiamos formato fecha al esperado por SP-API
        $ship_date_iso = $this->toIso8601UtcZ($ship_date);

        $success = $notifier->notifyShipment(
            $amazon_order_id,
            $marketplace_id,
            $tracking_number,
            $carrier_code,
            $shipping_method,
            $ship_date_iso,
            $order_items
        );

        //si $success es true pero fue porque el pedido ya estaba confirmado en Amazon, lo indicamos
        $lastErr = $notifier->getLastError();
        if ($success && !empty($lastErr['code']) && $lastErr['code'] === 'ALREADY_FULFILLED') {
            // Lo guardamos como “info” en notification_error (no como error)
            $raw = !empty($lastErr['context']['raw']) ? $lastErr['context']['raw'] : '';
            $this->appendNotificationError(
                $id_amazonfrik_orders,
                'ALREADY_FULFILLED',
                $this->truncate($lastErr['message'] . ($raw ? ' | RAW: ' . $raw : ''), 1000)
            );
        }

        // 5. Actualizar BD
        $update_data = [
            'tracking_number' => pSQL($tracking_number),
            'carrier_code' => pSQL($carrier_code),
            'shipping_method' => pSQL($shipping_method),
            'ship_date' => pSQL($ship_date),
            'last_notification_attempt' => date('Y-m-d H:i:s'),
            'shipment_notified' => (int) $success,
            'id_order_status_when_confirmed' => (int) $id_order_status,
            'order_status' => $success ? 'shipped' : 'error',
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if (!$success) {
            $err = $notifier->getLastError();
            $code = !empty($err['code']) ? $err['code'] : 'SPAPI_ERROR';
            $msg = !empty($err['message']) ? $err['message'] : 'Error SP-API';

            $this->appendNotificationError($id_amazonfrik_orders, $code, $this->truncate($msg, 1000));
            $this->setShipmentResult(false, $code, $msg, $context);

            // Importante: NO pisar notification_error aquí
            unset($update_data['notification_error']);
        } else {
            $codeForResult = (!empty($lastErr['code']) && $lastErr['code'] === 'ALREADY_FULFILLED') ? 'ALREADY_FULFILLED' : 'OK';
            $msgForResult = ($codeForResult === 'ALREADY_FULFILLED')
                ? 'Amazon indica que el pedido ya estaba fulfilled / no hay paquete a actualizar. Se marca como confirmado.'
                : 'Envío confirmado correctamente en Amazon.';

            $this->setShipmentResult(true, $codeForResult, $msgForResult, $context);
        }

        Db::getInstance()->update(
            'amazonfrik_orders',
            $update_data,
            'id_amazonfrik_orders = ' . (int) $id_amazonfrik_orders
        );

        return (bool) $success;
    }

    protected function truncate($s, $max = 450)
    {
        $s = (string) $s;
        if (Tools::strlen($s) <= $max)
            return $s;
        return Tools::substr($s, 0, $max - 3) . '...';
    }

    /**
     * Obtiene el id_reference de un carrier
     *
     * @param int $id_carrier
     * @return int|null
     */
    protected function getCarrierReference($id_carrier)
    {
        return (int) Db::getInstance()->getValue('
            SELECT id_reference FROM `' . _DB_PREFIX_ . 'carrier`
            WHERE id_carrier = ' . (int) $id_carrier
        );
    }

    /**
     * Procesa pedidos pendientes de confirmación en Amazon
     * Se ejecuta vía cron
     */
    public function processPendingShipments()
    {
        $logger = new LoggerFrik(
            _PS_MODULE_DIR_ . 'amazonfrik/logs/cron_shipment_confirmation_' . date('Ymd') . '.txt',
            true,
            'sergio@lafrikileria.com'
        );
        $logger->log('================================================', 'INFO', false);
        $logger->log('Iniciando proceso de reintentos de envíos', 'INFO', false);

        $states = implode(',', array_map('intval', $this->shipped_states));
        $cutoff = date('Y-m-d H:i:s', strtotime('-2 hour'));

        // Cogemos el último order_carrier por pedido
        $sql = '
            SELECT 
                af.id_amazonfrik_orders,
                af.id_order,
                af.amazon_order_id,
                af.marketplace_id,
                af.tracking_number AS af_tracking,
                af.last_notification_attempt,
                af.order_status,
                o.current_state,
                oc.tracking_number AS oc_tracking
            FROM `' . _DB_PREFIX_ . 'amazonfrik_orders` af
            INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON af.id_order = o.id_order
            LEFT JOIN `' . _DB_PREFIX_ . 'order_carrier` oc 
                ON oc.id_order = o.id_order
                AND oc.id_order_carrier = (
                    SELECT MAX(oc2.id_order_carrier)
                    FROM `' . _DB_PREFIX_ . 'order_carrier` oc2
                    WHERE oc2.id_order = o.id_order
                )
            WHERE af.shipment_notified = 0
            AND af.order_status IN ("imported", "error")
            AND o.current_state IN (' . $states . ')
            AND (af.last_notification_attempt IS NULL OR af.last_notification_attempt < "' . pSQL($cutoff) . '")
            ORDER BY af.date_add ASC
            LIMIT 1
        ';

        $orders = Db::getInstance()->executeS($sql);

        if (empty($orders)) {
            $logger->log('No hay pedidos pendientes que cumplan condiciones.', 'INFO', false);
            return 0;
        }

        $processed = 0;

        foreach ($orders as $row) {
            $id_af = (int) $row['id_amazonfrik_orders'];
            $id_order = (int) $row['id_order'];
            $amazon_order_id = (string) $row['amazon_order_id'];
            $current_state = (int) $row['current_state'];

            $logger->log("---- Pedido: id_amazonfrik_orders={$id_af} | id_order={$id_order} | amazon_order_id={$amazon_order_id} | state={$current_state}", 'INFO');

            // 1) Tracking candidato: prioriza oc_tracking (order_carrier) si es válido, sobre af_tracking (amazonfrik_orders)
            $oc_tracking = isset($row['oc_tracking']) ? trim((string) $row['oc_tracking']) : '';
            $af_tracking = isset($row['af_tracking']) ? trim((string) $row['af_tracking']) : '';

            $tracking = '';
            if ($this->isValidTracking($oc_tracking)) {
                $tracking = $oc_tracking;
            } elseif ($this->isValidTracking($af_tracking)) {
                $tracking = $af_tracking;
            }

            // 2) Si no hay tracking válido -> error + backoff 
            if (!$this->isValidTracking($tracking)) {
                $msg = 'INVALID_TRACKING - Tracking no válido para confirmar envío. oc="' . $oc_tracking . '" af="' . $af_tracking . '"';
                $this->appendNotificationError($id_af, 'INVALID_TRACKING', $msg);
                $logger->log("ERROR: {$msg}", 'ERROR');
                continue;
            }

            // 3) Guardar tracking en amazonfrik_orders: si el elegido es el de oc (válido) y:
            //    - af está vacío/ inválido, o
            //    - af es distinto
            $need_tracking_update = false;

            if ($tracking === $oc_tracking) {
                if (!$this->isValidTracking($af_tracking) || $af_tracking !== $oc_tracking) {
                    $need_tracking_update = true;
                }
            }

            if ($need_tracking_update) {
                Db::getInstance()->update(
                    'amazonfrik_orders',
                    [
                        'tracking_number' => pSQL($tracking),
                        'date_upd' => date('Y-m-d H:i:s'),
                    ],
                    'id_amazonfrik_orders=' . (int) $id_af
                );
                $logger->log('Tracking actualizado en amazonfrik_orders. af="' . $af_tracking . '" -> oc="' . $oc_tracking . '"', 'INFO');
            }           

            // Intento de confirmación (aquí dentro ya se actualiza order_status/shipment_notified/notification_error/last_notification_attempt)
            $success = false;
            try {
                $success = (bool) $this->markOrderAsShippedInAmazon(
                    $id_af,
                    $tracking,
                    date('Y-m-d H:i:s'),
                    $current_state
                );
            } catch (Exception $e) {
                // Por si algo se escapa de markOrderAsShippedInAmazon
                $msg = 'EXCEPTION - ' . $e->getMessage();
                $this->appendNotificationError($id_af, 'EXCEPTION', $e->getMessage());

                Db::getInstance()->update(
                    'amazonfrik_orders',
                    [
                        'order_status' => 'error',
                        'shipment_notified' => 0,
                        'date_upd' => date('Y-m-d H:i:s'),
                    ],
                    'id_amazonfrik_orders=' . (int) $id_af
                );

                $logger->log("EXCEPTION al confirmar envío: {$msg}", 'ERROR');
                $processed++;
                continue;
            }

            if ($success) {
                $logger->log("OK - Confirmado en Amazon: amazon_order_id={$amazon_order_id}", 'INFO');
            } else {
                // markOrderAsShippedInAmazon ya habrá dejado notification_error.
                // Aun así, logueamos un resumen
                $res = $this->getLastShipmentResult();
                $code = !empty($res['code']) ? $res['code'] : 'UNKNOWN';
                $m = !empty($res['message']) ? $res['message'] : 'Fallo sin mensaje';
                $logger->log("KO - No confirmado: code={$code} msg={$m}", 'ERROR');
            }

            $processed++;
        }

        $logger->log("Proceso finalizado. Pedidos intentados: {$processed}", 'INFO');
        return $processed;
    }

    /**
     * Añade un mensaje a notification_error SIN pisar el anterior, con fecha.
     * Limitar el tamaño total para evitar crecer infinito .
     */
    public function appendNotificationError($id_amazonfrik_orders, $code, $message, $ts = null, $maxLen = 2000)
    {
        $ts = $ts ?: date('Y-m-d H:i:s');
        $code = preg_replace('/[^A-Z0-9_\-]/i', '', (string) $code);
        $message = trim((string) $message);

        if (!(int) $id_amazonfrik_orders || $message === '') {
            return false;
        }

        $current = Db::getInstance()->getValue('
            SELECT notification_error
            FROM `' . _DB_PREFIX_ . 'amazonfrik_orders`
            WHERE id_amazonfrik_orders = ' . (int) $id_amazonfrik_orders
        );

        $line = '[' . $ts . '] ' . $code . ' - ' . $message;

        // Encadenar (lo nuevo arriba, para verlo rápido)
        $new = $line;
        if (!empty($current)) {
            $new .= "\n" . $current;
        }

        // Recortar si se pasa de largo
        if (Tools::strlen($new) > $maxLen) {
            $new = Tools::substr($new, 0, $maxLen - 3) . '...';
        }

        return Db::getInstance()->update(
            'amazonfrik_orders',
            [
                'notification_error' => pSQL($new, true),
                'last_notification_attempt' => $ts,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            'id_amazonfrik_orders = ' . (int) $id_amazonfrik_orders
        );
    }

    //función para cambiar el formato fecha que llega de la API a Y-m-d H:i:s con hora local de Madrid
    protected function isoZToMysql($iso)
    {
        if (empty($iso))
            return null;

        try {
            $dt = new DateTime($iso); // entiende la Z como UTC

            $dt->setTimezone(new DateTimeZone('Europe/Madrid'));

            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    //función para cambiar la fecha de formato Y-m-d H:i:s a formato 2025-12-30T11:12:02Z que espera la api
    protected function toIso8601UtcZ($dt)
    {
        try {
            $d = new DateTime($dt, new DateTimeZone(Configuration::get('PS_TIMEZONE')));
            $d->setTimezone(new DateTimeZone('UTC')); //esto además pone una hora menos que madrid
            return $d->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
    }

    protected function toIso8601($dt)
    {
        // Si ya viene tipo ISO (contiene 'T'), lo devolvemos tal cual
        if (is_string($dt) && strpos($dt, 'T') !== false) {
            return $dt;
        }

        try {
            // Usa timezone de PS, y lo manda con offset (+01:00, etc.)
            $tz = new DateTimeZone(Configuration::get('PS_TIMEZONE'));
            $d = new DateTime($dt, $tz);
            return $d->format('c'); // ISO 8601 con offset
        } catch (Exception $e) {
            // fallback: ahora mismo en ISO
            return date('c');
        }
    }

    //función que comprueba que $tracking no esté vacío, sea muy corto, etc para evitar en lo posible confirmar un pedido sin tracking
    public function isValidTracking($tracking)
    {
        if (!is_string($tracking)) {
            return false;
        }

        $tracking = trim($tracking);

        // Vacío o demasiado corto
        if ($tracking === '' || Tools::strlen($tracking) < 5) {
            return false;
        }

        // Placeholders comunes
        $invalid = [
            'N/A',
            'NA',
            'NONE',
            'PENDING',
            'PREPARING',
            'ETIQUETADO',
            'LABEL',
            'SIN TRACKING',
            'NO TRACKING',
            '0',
            '-',
            '--',
            '---',
            '----',
            '-----',
        ];

        if (in_array(Tools::strtoupper($tracking), $invalid, true)) {
            return false;
        }

        //si todos los caracteres son iguales también invalida
        if (preg_match('/^(.)\1{4,}$/', $tracking)) {
            return false;
        }

        return true;
    }




    //esta función solo sirve para una carga inicial al instalar el módulo, para no dejar fuera los pedidos que ya han entrado a Pestashop
    /**
     * Migra pedidos existentes de Amazon con todos los datos
     */
    public function migrateExistingAmazonOrders()
    {
        $excluded_states = [4, 5, 6, 29, 46, 47, 64, 65, 85]; // enviados, entregados, cancelados, devueltos

        //sacamos pedidos en estados $excluded_states y además los que estén en estado $shipped cuyo cambio a ese estado haya sido en las últimas 24 horas
        //o en las horas en que no se haya revisado
        $hours = 13;

        $sql = '
            SELECT DISTINCT
                o.id_order,
                o.ext_order_id AS amazon_order_id,
                o.ext_origin AS marketplace,
                o.ext_shipping_deadline AS shipping_deadline
            FROM `' . _DB_PREFIX_ . 'orders` o
            LEFT JOIN `' . _DB_PREFIX_ . 'amazonfrik_orders` af 
                ON af.amazon_order_id = o.ext_order_id
            WHERE 
                o.ext_origin LIKE "Amazon.%" 
                AND o.module = "amazon"
                AND o.ext_order_id IS NOT NULL
                AND af.id_amazonfrik_orders IS NULL
                AND (
                    -- 1) Tu criterio original: “pendientes” (no finales)
                    o.current_state NOT IN (' . implode(',', array_map('intval', $excluded_states)) . ')

                    OR

                    -- 2) Extra: los que YA están shipped/etiquetados y han cambiado hace poco
                    (
                        o.current_state IN (' . implode(',', array_map('intval', $this->shipped_states)) . ')
                        AND EXISTS (
                            SELECT 1
                            FROM `' . _DB_PREFIX_ . 'order_history` oh
                            WHERE oh.id_order = o.id_order
                            AND oh.id_order_state = o.current_state
                            AND oh.date_add >= DATE_SUB(NOW(), INTERVAL ' . (int) $hours . ' HOUR)
                        )
                    )
                )
            ORDER BY o.id_order ASC
        ';

        $orders = Db::getInstance()->executeS($sql);
        $migrated_count = 0;

        foreach ($orders as $order) {
            $marketplace_id = $this->getMarketplaceIdByOrigin($order['marketplace']);
            if (!$marketplace_id) {
                error_log('[AmazonFrik] Marketplace desconocido para id_order ' . $order['id_order'] . ' - ' . $order['amazon_order_id'] . ': ' . $order['marketplace']);
                echo '<br>Marketplace desconocido para id_order ' . $order['id_order'] . ' - ' . $order['amazon_order_id'] . ': ' . $order['marketplace'];
                continue;
            }

            //nos aseguramos de que no existe ya la combinación amazon_order_id/ marketplace id
            $exists = (int) Db::getInstance()->getValue('
                SELECT 1
                FROM `' . _DB_PREFIX_ . 'amazonfrik_orders`
                WHERE amazon_order_id = "' . pSQL($order['amazon_order_id']) . '"
                    AND marketplace_id = "' . pSQL($marketplace_id) . '"                
                ');

            if ($exists) {
                continue; // ya estaba
            }

            // Obtener datos adicionales de Amazon
            $importer = new AmazonOrderImporter();
            $order_data = $importer->getOrderDataFromAmazon($order['amazon_order_id']);

            // Insertar en amazonfrik_orders
            $result = Db::getInstance()->insert(
                'amazonfrik_orders',
                [
                    'amazon_order_id' => pSQL($order['amazon_order_id']),
                    'marketplace' => pSQL($order['marketplace']),
                    'marketplace_id' => pSQL($marketplace_id),
                    'id_order' => (int) $order['id_order'],
                    'order_status' => 'imported',
                    'shipping_deadline' => $order['shipping_deadline'] ?: null,
                    'purchase_date' => $this->isoZToMysql($order_data['purchase_date'] ?? null),
                    'ship_service_level' => $order_data['ship_service_level'] ?? null,
                    'order_total' => $order_data['order_total'] ?? null,
                    'currency' => $order_data['currency'] ?? null,
                    'date_add' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s'),
                ],
                false,
                true
            );

            if ($result) {
                $migrated_count++;
                $id_amazonfrik_orders = Db::getInstance()->Insert_ID();
                if (!$id_amazonfrik_orders && method_exists(Db::getInstance(), 'insert_ID')) {
                    $id_amazonfrik_orders = (int) Db::getInstance()->insert_ID();
                }

                // Precargar orderItems (guardará en amazonfrik_order_detail)
                $notifier = new AmazonShipmentNotifier();
                $order_items = $notifier->getOrderItemsForShipment(
                    $order['amazon_order_id'],
                    $id_amazonfrik_orders
                );
            }
        }

        return $migrated_count;
    }

}