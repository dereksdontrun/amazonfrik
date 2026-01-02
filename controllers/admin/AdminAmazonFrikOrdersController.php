<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'amazonfrik/classes/AmazonFrikOrder.php';

class AdminAmazonFrikOrdersController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'amazonfrik_orders';
        $this->className = 'AmazonFrikOrder';
        $this->lang = false;
        $this->bootstrap = true;

        // Campos a mostrar en la lista
        $this->fields_list = [
            'id_amazonfrik_orders' => [
                'title' => 'ID',
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ],
            'amazon_order_id' => [
                'title' => 'ID Pedido Amazon',
                'orderby' => true
            ],
            'marketplace' => [
                'title' => 'Marketplace',
                'orderby' => true
            ],
            'id_order' => [
                'title' => 'Pedido PrestaShop',
                'callback' => 'printOrderLink',
                'remove_onclick' => true, //para que al pulsar el link de id_order no entre en este controlador, pero solo en esta columna
            ],
            'order_status' => [
                'title' => 'Estado',
                'callback' => 'renderOrderStatus',
                'type' => 'select',
                'list' => [
                    'imported' => 'Importado',
                    'shipped' => 'Enviado',
                    'error' => 'Error'
                ],
                'filter_key' => 'a!order_status'
            ],
            'shipment_notified' => [
                'title' => 'Notificado',
                'type' => 'bool',
                'class' => 'fixed-width-sm'
            ],
            'tracking_number' => [
                'title' => 'Tracking',
                'orderby' => false
            ],
            'carrier_code' => [
                'title' => 'Transportista',
                'orderby' => false
            ],
            'ship_date' => [
                'title' => 'Fecha Envío',
                'type' => 'datetime',
                'orderby' => true
            ],
            'purchase_date' => [
                'title' => 'Compra (Amazon)',
                'type' => 'datetime',
                'orderby' => true,
            ],
            'date_add' => [
                'title' => 'Añadido',
                'type' => 'datetime',
                'orderby' => true
            ],
        ];

        // Opciones del selector de paginación
        $this->_pagination = array(20, 50, 100, 300);

        // Valor por defecto
        $this->_defaultPagination = 50;

        // Botones de acción
        $this->addRowAction('edit');
        $this->addRowAction('retry');

        parent::__construct();
    }

    public function renderOrderStatus($value, $row = null)
    {
        $map = [
            'imported' => ['Importado', '#3498db'], // azul
            'shipped' => ['Confirmado', '#27ae60'], // verde
            'error' => ['Error', '#e74c3c'], // rojo ( #e67e22 para naranja)
        ];

        if (!isset($map[$value])) {
            return (string) $value;
        }

        list($label, $color) = $map[$value];

        return '<span style="
                display:inline-block;
                padding:2px 8px;
                border-radius:12px;
                font-size:12px;
                line-height:18px;
                color:#fff;
                background:' . pSQL($color) . ';
                font-weight:600;
            ">' . Tools::safeOutput($label) . '</span>';
    }

    /**
     * Muestra un enlace al pedido de PrestaShop
     */
    public function printOrderLink($id_order)
    {
        if (!$id_order) {
            return '--';
        }
        $url = $this->context->link->getAdminLink('AdminOrders') . '&id_order=' . (int) $id_order . '&vieworder';
        return '<a href="' . $url . '" target="_blank">' . (int) $id_order . '</a>';
    }

    /**
     * Muestra el botón de retry (solo si shipment_notified = 0)
     */
    public function displayRetryLink($token = null, $id = 0)
    {
        // Comprobamos si el shipment ya está notificado
        $notified = Db::getInstance()->getValue('
            SELECT shipment_notified
            FROM ' . _DB_PREFIX_ . 'amazonfrik_orders
            WHERE id_amazonfrik_orders = ' . (int) $id
        );

        if ((int) $notified == 1) {
            // Si no existe o ya está resuelto, no mostramos botón
            return '';
        }

        // En PS 1.6: getAdminLink solo con controller y token
        $link = $this->context->link->getAdminLink('AdminAmazonFrikOrders', true);

        // añadimos parámetros a mano
        $link .= '&' . $this->identifier . '=' . (int) $id;
        $link .= '&retry=1';

        return '<a href="' . $link . '" class="btn btn-sm btn-default" 
                onclick="return confirm(\'¿Reintentar la confirmación de envío?\')" 
                title="Reintentar">
                <i class="icon-refresh"></i> Reintentar
            </a>';
    }

    /**
     * Acción personalizada: reintentar notificación. En caso de errores se devuelve un msg en la url para que no se pierda al recargar, ya que vamos a amazonfrik.php
     */
    public function processRetry()
    {
        $base = $this->context->link->getAdminLink('AdminAmazonFrikOrders', true);

        if (!($id = (int) Tools::getValue('id_amazonfrik_orders'))) {
            Tools::redirectAdmin($base . '&msg=missing_id');
        }

        $amazon_frik_order = new AmazonFrikOrder($id);
        if (!Validate::isLoadedObject($amazon_frik_order)) {
            Tools::redirectAdmin($base . '&msg=not_found');
        }

        //buscamos el tracking, primero en amazonfrik_orders, luego en ordercarrier
        $tracking = (string) $amazon_frik_order->tracking_number;

        if (!$this->module->isValidTracking($tracking)) {
            //no es válido, buscamos en order_carrier
            $id_order_carrier = (int) Db::getInstance()->getValue('
                SELECT id_order_carrier
                FROM `' . _DB_PREFIX_ . 'order_carrier`
                WHERE id_order = ' . (int) $amazon_frik_order->id_order . '
                ORDER BY id_order_carrier DESC
            ');
            if ($id_order_carrier) {
                $oc = new OrderCarrier($id_order_carrier);
                $tracking = (string) $oc->tracking_number;

                // si lo hemos encontrado aquí, lo guardamos en amazonfrik_orders
                if ($this->module->isValidTracking($tracking)) {
                    Db::getInstance()->update(
                        'amazonfrik_orders',
                        [
                            'tracking_number' => pSQL($tracking),
                            'date_upd' => date('Y-m-d H:i:s'),
                        ],
                        'id_amazonfrik_orders=' . (int) $id
                    );
                }
            }
        }

        //validación final, si no es válido no reintentamos
        if (!$this->module->isValidTracking($tracking)) {
            $this->module->appendNotificationError(
                $id,
                'INVALID_TRACKING',
                'Retry manual: tracking inválido o vacío. Valor="' . $tracking . '"'
            );

            Tools::redirectAdmin($base . '&msg=invalid_tracking&id_amazonfrik_orders=' . (int) $id);
        }

        //obtenemos estado actual de pedido
        $order = new Order($amazon_frik_order->id_order);
        $id_order_status_when_confirmed = (int) $order->current_state;

        $success = false;
        try {
            $success = $this->module->markOrderAsShippedInAmazon(
                $id,
                $tracking,
                date('Y-m-d H:i:s'),
                (int) $id_order_status_when_confirmed
            );
        } catch (Exception $e) {
            $this->module->appendNotificationError($id, 'EXCEPTION', 'Retry manual: ' . $e->getMessage());

            Db::getInstance()->update(
                'amazonfrik_orders',
                [
                    'order_status' => 'error',
                    'shipment_notified' => 0,                    
                    'date_upd' => date('Y-m-d H:i:s'),
                ],
                'id_amazonfrik_orders = ' . (int) $id
            );

            Tools::redirectAdmin($base . '&msg=retry_ko&code=' . urlencode('EXCEPTION') . '&id_amazonfrik_orders=' . $id);
        }

        $res = $this->module->getLastShipmentResult();
        $code = !empty($res['code']) ? $res['code'] : 'UNKNOWN';

        if ($success) {
            Tools::redirectAdmin($base . '&msg=retry_ok&code=' . urlencode($code) . '&id_amazonfrik_orders=' . $id);
        } else {
            Tools::redirectAdmin($base . '&msg=retry_ko&code=' . urlencode($code) . '&id_amazonfrik_orders=' . $id);
        }
    }

    /**
     * Renderiza la vista de detalle
     */
    public function renderForm()
    {
        // Obtener el ID desde la URL
        $id = (int) Tools::getValue($this->identifier);
        if (!$id) {
            $this->errors[] = 'ID de pedido no proporcionado';
            return parent::renderForm();
        }

        // Cargar el objeto manualmente
        $order = new AmazonFrikOrder($id);
        if (!Validate::isLoadedObject($order)) {
            $this->errors[] = 'Pedido no encontrado';
            return parent::renderForm();
        }

        // Configurar el formulario (solo lectura)
        $this->fields_form = array(
            'legend' => array(
                'title' => 'Detalles del Pedido Amazon',
                'icon' => 'icon-info'
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => 'ID Pedido Amazon',
                    'name' => 'amazon_order_id',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Marketplace',
                    'name' => 'marketplace',
                    'readonly' => true
                ),
                array(
                    'type' => 'html',
                    'label' => 'Pedido PrestaShop',
                    'name' => 'id_order',
                    'html_content' => $this->printOrderLink($order->id_order)
                ),
                array(
                    'type' => 'text',
                    'label' => 'Estado',
                    'name' => 'order_status',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Notificado',
                    'name' => 'shipment_notified',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Tracking',
                    'name' => 'tracking_number',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Código Transportista',
                    'name' => 'carrier_code',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Método Envío',
                    'name' => 'shipping_method',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Nivel Servicio Envío',
                    'name' => 'ship_service_level',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Total Pedido',
                    'name' => 'order_total_display',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Fecha Límite Envío',
                    'name' => 'shipping_deadline',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Fecha Envío',
                    'name' => 'ship_date',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Compra (Amazon)',
                    'name' => 'purchase_date',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Último Intento',
                    'name' => 'last_notification_attempt',
                    'readonly' => true
                ),
                array(
                    'type' => 'textarea',
                    'label' => 'Error Notificación',
                    'name' => 'notification_error',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Fecha Alta',
                    'name' => 'date_add',
                    'readonly' => true
                ),
                array(
                    'type' => 'text',
                    'label' => 'Fecha Actualización',
                    'name' => 'date_upd',
                    'readonly' => true
                )
            ),
            // 'submit' => array(
            //     'title' => 'Volver',
            //     'class' => 'btn btn-default',
            //     'icon' => 'process-icon-back'
            // ),
            'buttons' => array(
                array(
                    'href' => $this->context->link->getAdminLink('AdminAmazonFrikOrders', true),
                    'title' => 'Volver a la lista',
                    'icon' => 'process-icon-back',
                    'class' => 'pull-left'
                ),
            )
        );

        // Añadir el botón de retry al final del formulario si aún no se ha confirmado el shipping
        if ((int) $order->shipment_notified === 0) {
            $retry_link = $this->context->link->getAdminLink('AdminAmazonFrikOrders', true);
            $retry_link .= '&' . $this->identifier . '=' . (int) $id;
            $retry_link .= '&retry=1';

            $this->fields_form['buttons'][] = array(
                'href' => $retry_link,
                'title' => 'Reintentar confirmación',
                'icon' => 'process-icon-refresh',
                'class' => 'pull-right',
                'js' => 'return confirm(\'¿Reintentar la confirmación de envío?\');'
            );
        }

        // Rellenar los valores
        $this->fields_value['amazon_order_id'] = $order->amazon_order_id;
        $this->fields_value['marketplace'] = $order->marketplace;
        $this->fields_value['order_status'] = $order->order_status;
        $this->fields_value['shipment_notified'] = $order->shipment_notified ? 'Sí' : 'No';
        $this->fields_value['tracking_number'] = $order->tracking_number;
        $this->fields_value['carrier_code'] = $order->carrier_code;
        $this->fields_value['shipping_method'] = $order->shipping_method;
        $this->fields_value['ship_service_level'] = $order->ship_service_level;
        $this->fields_value['order_total_display'] = $order->order_total ? $order->order_total . ' ' . $order->currency : null;
        $this->fields_value['shipping_deadline'] = $order->shipping_deadline;
        $this->fields_value['ship_date'] = $order->ship_date;
        $this->fields_value['last_notification_attempt'] = $order->last_notification_attempt;
        $this->fields_value['notification_error'] = $order->notification_error;
        $this->fields_value['date_add'] = $order->date_add;
        $this->fields_value['date_upd'] = $order->date_upd;

        return parent::renderForm();
    }

    /**
     * Añade botones personalizados en la lista
     */
    // public function initToolbar()
    // {
    //     parent::initToolbar();

    //     // Añadir botón de migración
    //     // $this->toolbar_btn['migrate'] = [
    //     //     'href' => $this->context->link->getAdminLink('AdminAmazonFrikOrders') . '&migrate=1',
    //     //     'desc' => 'Migrar pedidos existentes',
    //     //     'icon' => 'process-icon-update'
    //     // ];

    //     // Añadir botón de reintentar todos
    //     $this->toolbar_btn['retry_all'] = [
    //         'href' => $this->context->link->getAdminLink('AdminAmazonFrikOrders') . '&retryall=1',
    //         'desc' => 'Reintentar todos los envíos pendientes',
    //         'icon' => 'process-icon-refresh'
    //     ];
    // }

    /**
     * Procesa acciones personalizadas en la toolbar
     */
    public function postProcess()
    {
        // Migrar pedidos existentes
        // if (Tools::isSubmit('migrate')) {
        //     $count = $this->module->migrateExistingAmazonOrders();
        //     $this->confirmations[] = "Migrados {$count} pedidos";
        //     Tools::redirectAdmin($this->context->link->getAdminLink('AdminAmazonFrikOrders'));
        // }

        // Procesar acción de retry individual
        // if (Tools::isSubmit('retry')) {
        //     $this->processRetry();
        //     return; // detener aquí para evitar procesamiento adicional
        // }

        // Reintentar todos los pedidos pendientes
        // if (Tools::isSubmit('retryall')) {
        //     $count = $this->module->processPendingShipments();
        //     $this->confirmations[] = "Procesados {$count} envíos pendientes";
        //     Tools::redirectWithToken($this->context->link->getAdminLink('AdminAmazonFrikOrders'));
        // }

        // Mensajes tras redirect
        $msg = Tools::getValue('msg');
        if ($msg) {
            switch ($msg) {
                case 'retry_ok':
                    $this->confirmations[] = '¡Envío confirmado correctamente en Amazon!';
                    break;
                case 'retry_ko':
                    $this->errors[] = 'No se pudo confirmar el envío. Revisa logs y el campo "Error Notificación".';
                    break;
                case 'missing_id':
                    $this->errors[] = 'ID no proporcionado.';
                    break;
                case 'not_found':
                    $this->errors[] = 'Pedido no encontrado.';
                    break;
                case 'invalid_tracking':
                    $this->errors[] = 'No hay número de seguimiento en el pedido (order_carrier). No se puede confirmar envío.';
                    break;

            }
        }

        // Acción retry
        if (Tools::isSubmit('retry')) {
            $this->processRetry();
            return;
        }

        parent::postProcess();

    }
}