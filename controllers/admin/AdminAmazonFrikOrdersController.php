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
            'id_amazonfrik_order' => [
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
                'callback' => 'printOrderLink'
            ],
            'order_status' => [
                'title' => 'Estado',
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
            'date_add' => [
                'title' => 'Añadido',
                'type' => 'datetime',
                'orderby' => true
            ]
        ];

        // Botones de acción
        $this->addRowAction('view');
        $this->addRowAction('retry');
        
        parent::__construct();
    }

    /**
     * Muestra un enlace al pedido de PrestaShop
     */
    public function printOrderLink($id_order)
    {
        if (!$id_order) {
            return '--';
        }
        $url = $this->context->link->getAdminLink('AdminOrders') . '&id_order=' . (int)$id_order . '&vieworder';
        return '<a href="' . $url . '" target="_blank">' . (int)$id_order . '</a>';
    }

    /**
     * Acción personalizada: reintentar notificación
     */
    public function processRetry()
    {
        if (!($id = Tools::getValue('id_amazonfrik_order'))) {
            $this->errors[] = 'ID no proporcionado';
            return;
        }

        $order = new AmazonFrikOrder((int)$id);
        if (!Validate::isLoadedObject($order)) {
            $this->errors[] = 'Pedido no encontrado';
            return;
        }

        // Llamar al módulo principal para reintentar
        $module = $this->module;
        $success = $module->markOrderAsShippedInAmazon(
            (int)$id,
            $order->tracking_number,
            date('Y-m-d')
        );

        if ($success) {
            $this->confirmations[] = '¡Envío confirmado correctamente!';
        } else {
            $this->errors[] = 'Error al confirmar el envío. Consulta los logs para más detalles.';
        }

        // Redirigir de vuelta a la lista
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminAmazonFrikOrders'));
    }

    /**
     * Renderiza la vista de detalle
     */
    public function renderView()
    {
        /** @var AmazonFrikOrder $order */
        $order = $this->loadObject();

        // Datos del pedido
        $fields = [
            'ID Pedido Amazon' => $order->amazon_order_id,
            'Marketplace' => $order->marketplace,
            'Pedido PrestaShop' => $this->printOrderLink($order->id_order),
            'Estado' => $order->order_status,
            'Notificado' => $order->shipment_notified ? 'Sí' : 'No',
            'Tracking' => $order->tracking_number ?: '--',
            'Código Transportista' => $order->carrier_code ?: '--',
            'Método Envío' => $order->shipping_method ?: '--',
            'Nivel Servicio Envío' => $order->ship_service_level ?: '--',
            'Total Pedido' => $order->order_total ? $order->order_total . ' ' . $order->currency : '--',
            'Límite Envío' => $order->shipping_deadline ?: '--',
            'Fecha Envío' => $order->ship_date ?: '--',
            'Último Intento' => $order->last_notification_attempt ?: '--',
            'Error Notificación' => $order->notification_error ?: '--',
            'Fecha Alta' => $order->date_add,
            'Fecha Actualización' => $order->date_upd,
        ];

        $this->tpl_view_vars = [
            'order_fields' => $fields,
            'order' => $order
        ];

        return parent::renderView();
    }

    /**
     * Añade botones personalizados en la lista
     */
    public function initToolbar()
    {
        parent::initToolbar();

        // Añadir botón de migración
        $this->toolbar_btn['migrate'] = [
            'href' => $this->context->link->getAdminLink('AdminAmazonFrikOrders') . '&migrate=1',
            'desc' => 'Migrar pedidos existentes',
            'icon' => 'process-icon-update'
        ];

        // Añadir botón de reintentar todos
        $this->toolbar_btn['retry_all'] = [
            'href' => $this->context->link->getAdminLink('AdminAmazonFrikOrders') . '&retryall=1',
            'desc' => 'Reintentar todos los envíos pendientes',
            'icon' => 'process-icon-refresh'
        ];
    }

    /**
     * Procesa acciones personalizadas en la toolbar
     */
    public function postProcess()
    {
        // Migrar pedidos existentes
        if (Tools::isSubmit('migrate')) {
            $count = $this->module->migrateExistingAmazonOrders();
            $this->confirmations[] = "Migrados {$count} pedidos";
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminAmazonFrikOrders'));
        }

        // Reintentar todos los pedidos pendientes
        if (Tools::isSubmit('retryall')) {
            $count = $this->module->processPendingShipments();
            $this->confirmations[] = "Procesados {$count} envíos pendientes";
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminAmazonFrikOrders'));
        }

        parent::postProcess();
    }
}