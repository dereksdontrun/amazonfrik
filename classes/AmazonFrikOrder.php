
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Modelo ORM para la tabla amazonfrik_orders
 * Representa la relación entre un pedido de Amazon y su contraparte en PrestaShop
 */
class AmazonFrikOrder extends ObjectModel
{
    public $id_amazonfrik_order;
    public $amazon_orderja_id;
    public $marketplace;
    public $marketplace_id;
    public $id_order;
    public $order_status;
    public $shipment_notified;
    public $id_order_status_when_confirmed;
    public $tracking_number;
    public $carrier_code;
    public $shipping_method;
    public $shipping_deadline;
    public $ship_service_level;
    public $order_total;
    public $currency;
    public $ship_date;
    public $last_notification_attempt;
    public $notification_error;
    public $date_add;
    public $date_upd;

    public static $definition = array(
        'table' => 'amazonfrik_orders',
        'primary' => 'id_amazonfrik_order',
        'fields' => array(
            'amazon_order_id' => array('type' => self::TYPE_STRING, 'required' => true),
            'marketplace' => array('type' => self::TYPE_STRING, 'required' => true),
            'marketplace_id' => array('type' => self::TYPE_STRING, 'required' => true),
            'id_order' => array('type' => self::TYPE_INT),
            'order_status' => array('type' => self::TYPE_STRING),
            'shipment_notified' => array('type' => self::TYPE_BOOL),
            'id_order_status_when_confirmed' => array('type' => self::TYPE_INT),
            'tracking_number' => array('type' => self::TYPE_STRING),
            'carrier_code' => array('type' => self::TYPE_STRING),
            'shipping_method' => array('type' => self::TYPE_STRING),            
            'shipping_deadline' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'ship_service_level' => array('type' => self::TYPE_STRING),
            'order_total' => array('type' => self::TYPE_FLOAT),
            'currency' => array('type' => self::TYPE_STRING),
            'ship_date' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'last_notification_attempt' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'notification_error' => array('type' => self::TYPE_STRING),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );
}

/*
Ejemplo de uso:

// Crear nuevo registro
$order = new AmazonFrikOrder();
$order->amazon_order_id = '112-1234567-7890123';
$order->marketplace = 'Amazon.es';
$order->marketplace_id = 'A1F83G8C2ARO7P';
$order->id_order = 1234;
$order->order_status = 'imported';
$order->shipping_deadline = '2025-12-12 22:59:59'; // DATETIME
$order->ship_service_level = 'Std ES Dom_1';
$order->order_total = 91.41;
$order->currency = 'EUR';
$order->date_add = date('Y-m-d H:i:s');
$order->date_upd = date('Y-m-d H:i:s');
$order->save();

// Actualizar más tarde
$order->shipment_notified = 1;
$order->id_order_status_when_confirmed = 4;
$order->ship_date = '2025-12-10 14:25:00';
$order->save();


*/