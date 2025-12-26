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

        // Registrar hook para detectar cambios de estado
        if (!$this->registerHook('actionOrderStatusPostUpdate')) {
            return false;
        }

        return true;

        // Registrar hook personalizado NO ES NECESARIO PARA PERSONALIZADOS
        // $hook_name = 'actionAmazonOrderDataReady';
        // if (!Hook::getIdByName($hook_name)) {
        //     $new_hook = new Hook();
        //     $new_hook->name = $hook_name;
        //     $new_hook->title = 'Amazon Order Data Ready';
        //     $new_hook->description = 'Se llama cuando el módulo cambiatransportistaspringamazon tiene la info del pedido Amazon para PrestaShop';
        //     $new_hook->position = 0;
        //     $new_hook->add();
        // }

        // Crear tabla de control
        // return $this->createTables();
    }

    public function uninstall()
    {
        // $this->dropTables();
        return parent::uninstall();
    }

    private function createTables()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'amazonfrik_orders` (
            `id_amazonfrik_order` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `amazon_order_id` VARCHAR(64) NOT NULL,
            `marketplace` VARCHAR(64) NOT NULL,
            `marketplace_id` VARCHAR(30) NOT NULL,
            `id_order` INT(11) NULL DEFAULT NULL,
            `order_status` VARCHAR(20) NOT NULL DEFAULT "imported",
            `shipment_notified` TINYINT(1) NOT NULL DEFAULT "0",
            `id_order_status_when_confirmed` INT(10) NULL DEFAULT NULL,
            `tracking_number` VARCHAR(100) NULL DEFAULT NULL,
            `carrier_code` VARCHAR(50) NULL DEFAULT NULL,
            `shipping_method` VARCHAR(100) NULL DEFAULT NULL,
            `shipping_deadline` DATE NULL DEFAULT NULL,
            `ship_service_level` VARCHAR(50) NULL DEFAULT NULL,
            `order_total` DECIMAL(20,6) NULL DEFAULT NULL,
            `currency` VARCHAR(10) NULL DEFAULT NULL,
            `ship_date` DATE NULL DEFAULT NULL,
            `last_notification_attempt` DATETIME NULL DEFAULT NULL,
            `notification_error` TEXT NULL DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_amazonfrik_order`),
            UNIQUE KEY `uniq_amazon_order` (`amazon_order_id`, `marketplace_id`),
            KEY `idx_id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        if (!Db::getInstance()->execute($sql)) {
            return false;
        }

        return true;
    }

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

        $sql = 'SELECT id_amazonfrik_order FROM `' . _DB_PREFIX_ . 'amazonfrik_orders`
        WHERE `id_order` = ' . (int) $id_order . '
        AND `shipment_notified` = 0';

        $id_amazonfrik_order = Db::getInstance()->getValue($sql);

        if (!$id_amazonfrik_order) {
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
            return;
        }

        $order_carrier = new OrderCarrier($id_order_carrier);
        $tracking_number = $order_carrier->tracking_number ?: null;

        if (!$tracking_number) {
            return;
        }

        $this->markOrderAsShippedInAmazon(
            (int) $id_amazonfrik_order,
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
        $shipping_deadline = !empty($params['shipping_deadline']) ? pSQL($params['shipping_deadline']) : null;

        // Insertar en la tabla (ignorar duplicados)
        $result = Db::getInstance()->insert(
            'amazonfrik_orders',
            array(
                'amazon_order_id' => $amazon_order_id,
                'marketplace' => $marketplace_origin,
                'marketplace_id' => $marketplace_id,
                'id_order' => $id_order,
                'order_status' => 'imported',
                'shipping_deadline' => $shipping_deadline,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            false,
            true // $use_cache → esto hace INSERT IGNORE            
        );

        if ($result) {
            $logger->log("Pedido registrado: $id_order - $amazon_order_id en marketplace $marketplace_origin", 'INFO');
        } else {
            // Verifica si fue ignorado (duplicado) o error real
            $exists = Db::getInstance()->getValue('
                SELECT 1 FROM `' . _DB_PREFIX_ . 'amazonfrik_orders`
                WHERE amazon_order_id = "' . pSQL($amazon_order_id) . '"
                AND marketplace_id = "' . pSQL($marketplace_id) . '"
            ');
            if ($exists) {
                $logger->log("Pedido ya existía: $amazon_order_id", 'DEBUG');
            } else {
                $logger->log("Error al insertar pedido: $amazon_order_id", 'ERROR');
            }
        }

        return (bool) $result;
    }

    /**
     * Confirma el envío de un pedido en Amazon SP-API y actualiza el estado local
     *
     * @param int $id_amazonfrik_order ID en la tabla amazonfrik_orders
     * @param string|null $tracking_number Número de seguimiento
     * @param string $carrier_name Nombre del transportista en PrestaShop
     * @param string $ship_date Fecha de envío (Y-m-d H:i:s)
     * @param int|null $id_order_status ID de estado del pedido
     * @return bool Éxito de la operación
     */
    protected function markOrderAsShippedInAmazon($id_amazonfrik_order, $tracking_number, $ship_date, $id_order_status = null)
    {
        $logger = new LoggerFrik(
            _PS_MODULE_DIR_ . 'amazonfrik/logs/shipment_notify_' . date('Ymd') . '.txt',
            true,
            'sergio@lafrikileria.com'
        );
        $logger->log('================================================', 'INFO', false);
        $logger->log("Iniciando notificación de envío para amazonfrik_order ID: {$id_amazonfrik_order}", 'DEBUG');

        if (!$tracking_number) {
            $logger->log('Pedido sin tracking number', 'ERROR');
            return false;
        }

        $sql = 'SELECT amazon_order_id, marketplace_id, id_order 
            FROM `' . _DB_PREFIX_ . 'amazonfrik_orders`
            WHERE id_amazonfrik_order = ' . (int) $id_amazonfrik_order;

        $row = Db::getInstance()->getRow($sql);
        if (!$row) {
            $logger->log('Pedido no encontrado en amazonfrik_orders', 'ERROR');
            return false;
        }

        $amazon_order_id = $row['amazon_order_id'];
        $marketplace_id = $row['marketplace_id'];
        $id_order = (int) $row['id_order'];

        $logger->log("Procesando pedido Prestashop {$id_order} - Amazon: {$amazon_order_id} (Marketplace: {$marketplace_id})", 'INFO');

        // 1. Obtener id_reference del carrier
        $id_carrier = (int) Db::getInstance()->getValue('
            SELECT id_carrier FROM `' . _DB_PREFIX_ . 'orders`
            WHERE id_order = ' . (int) $id_order
        );

        $id_reference = $this->getCarrierReference($id_carrier);

        if (!$id_reference) {
            $logger->log("Carrier sin id_reference: {$id_carrier}", 'ERROR');
            return false;
        }

        // 2. Mapear a código Amazon, shipping method y nombre
        if (!isset($this->carrier_mapping[$id_reference])) {
            $logger->log("Carrier reference no mapeado: {$id_reference}", 'ERROR');
            return false;
        }

        list($carrier_code, $shipping_method, $carrier_name) = $this->carrier_mapping[$id_reference];

        $logger->log("Carrier mapeado {$carrier_name}: {$id_reference} → {$carrier_code} / {$shipping_method}", 'INFO');

        // 3. Obtener orderItems reales de Amazon
        $notifier = new AmazonShipmentNotifier($logger);
        $order_items = $notifier->getOrderItemsForShipment($amazon_order_id, $id_amazonfrik_order);
        if (empty($order_items)) {
            $logger->log('No se pudieron obtener los items del pedido de Amazon', 'ERROR');
            return false;
        }

        // 4. Notificar a Amazon
        $success = $notifier->notifyShipment(
            $amazon_order_id,
            $marketplace_id,
            $tracking_number,
            $carrier_code,
            $shipping_method,
            $ship_date,
            $order_items
        );

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
            $update_data['notification_error'] = 'Error en llamada a SP-API (ver logs)';
        } else {
            $update_data['notification_error'] = null;
        }

        Db::getInstance()->update(
            'amazonfrik_orders',
            $update_data,
            'id_amazonfrik_order = ' . (int) $id_amazonfrik_order
        );

        if ($success) {
            $logger->log("Envío confirmado exitosamente en Amazon para id_order {$id_order} - {$amazon_order_id}", 'INFO');
        } else {
            $logger->log("Error, fallo al confirmar envío en Amazon para id_order {$id_order} - {$amazon_order_id}", 'ERROR');
        }

        return $success;
    }

    /**
     * Obtiene el id_reference de un carrier
     *
     * @param int $id_carrier
     * @return int|null
     */
    protected function getCarrierReference($id_carrier)
    {
        return Db::getInstance()->getValue('
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

        $sql = '
            SELECT af.*, o.current_state
            FROM `' . _DB_PREFIX_ . 'amazonfrik_orders` af
            INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON af.id_order = o.id_order
            WHERE af.shipment_notified = 0
            AND af.order_status IN ("imported", "error") -- incluye errores previos
            AND o.current_state IN (' . implode(',', array_map('intval', $this->shipped_states)) . ')
            AND (af.last_notification_attempt IS NULL OR af.last_notification_attempt < "' . date('Y-m-d H:i:s', strtotime('-1 hour')) . '")
            ORDER BY af.date_add ASC
            LIMIT 20
        ';

        $orders = Db::getInstance()->executeS($sql);
        $processed = 0;

        foreach ($orders as $order) {
            $logger->log('Procesando pedido: ' . $order['amazon_order_id'], 'INFO');

            // Llamar a la función principal de confirmación
            $success = $this->markOrderAsShippedInAmazon(
                (int) $order['id_amazonfrik_order'],
                $order['tracking_number'], 
                date('Y-m-d H:i:s'),
                $order['current_state']
            );

            // Actualizar estado en BD
            $update_data = [
                'last_notification_attempt' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ];

            if ($success) {
                $update_data['shipment_notified'] = 1;
                $update_data['order_status'] = 'shipped';
                $update_data['notification_error'] = null;
                $logger->log('Confirmado: ' . $order['amazon_order_id'], 'INFO');
            } else {
                $update_data['order_status'] = 'error';
                // El error ya se loguea en markOrderAsShippedInAmazon
                $logger->log('Falló: ' . $order['amazon_order_id'], 'ERROR');
            }

            Db::getInstance()->update(
                'amazonfrik_orders',
                $update_data,
                'id_amazonfrik_order = ' . (int) $order['id_amazonfrik_order']
            );

            $processed++;
        }

        $logger->log("Proceso finalizado. Pedidos procesados: {$processed}", 'INFO');
        return $processed;
    }




    //esta función solo sirve para una carga inicial al instalar el módulo, para no dejar fuera los pedidos que ya han entrado a Pestashop
    /**
     * Migra pedidos existentes de Amazon con todos los datos
     */
    public function migrateExistingAmazonOrders()
    {
        $excluded_states = [4, 5, 6, 29, 64, 65, 85]; // enviados, entregados, cancelados

        $sql = '
        SELECT 
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
            AND af.id_amazonfrik_order IS NULL
            AND o.current_state NOT IN (' . implode(',', array_map('intval', $excluded_states)) . ')
        ORDER BY o.id_order ASC
    ';

        $orders = Db::getInstance()->executeS($sql);
        $migrated_count = 0;

        foreach ($orders as $order) {
            $marketplace_id = $this->getMarketplaceIdByOrigin($order['marketplace']);
            if (!$marketplace_id) {
                error_log('[AmazonFrik] Marketplace desconocido: ' . $order['marketplace']);
                continue;
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
                $id_amazonfrik_order = Db::getInstance()->Insert_ID();

                // Precargar orderItems (guardará en amazonfrik_order_detail)
                $this->getOrderItemsForShipment(
                    $order['amazon_order_id'],
                    $id_amazonfrik_order
                );
            }
        }

        return $migrated_count;
    }

}