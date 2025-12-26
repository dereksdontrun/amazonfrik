<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/AmazonSpApiClient.php';

class AmazonShipmentNotifier
{
    private $logger;

    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    public function notifyShipment($amazonOrderId, $marketplaceId, $trackingNumber, $carrierCode, $shippingMethod, $shipDate, $orderItems)
    {
        try {
            $client = new AmazonSpApiClient();

            $body = [
                'marketplaceId' => $marketplaceId,
                'packageDetail' => [
                    'packageReferenceId' => '1', // paquete único
                    'carrierCode' => $carrierCode,
                    'carrierName' => $carrierCode, 
                    'shippingMethod' => $shippingMethod,
                    'trackingNumber' => $trackingNumber,
                    'shipDate' => $shipDate,
                    'shipFromSupplySourceId' => 'Almacén Las Balsas',
                    'orderItems' => $orderItems
                ],
                'codCollectionMethod' => 'DirectPayment' // no hacemos contra reembolso
            ];
            
            $path = '/orders/v0/orders/' . urlencode($amazonOrderId) . '/shipmentConfirmation';
            //llamamos a la API, si es correcto la API no devuelve nada (HTTP 204 No Content) y si no es correcto, lanzamos allí una excepción que se recoge aquí
            $client->call('POST', $path, $body);

            if ($this->logger) {
                $this->logger->log("Envío confirmado: {$amazonOrderId}", 'INFO');
            }

            return true;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->log("Error al confirmar envío: " . $e->getMessage(), 'ERROR');
            }
            return false;
        }
    }

    /**
     * Obtiene los orderItems para confirmar envío.
     * Primero intenta desde la tabla local, luego desde la API.
     *
     * @param string $amazon_order_id
     * @param int $id_amazonfrik_order
     * @return array
     */
    public function getOrderItemsForShipment($amazon_order_id, $id_amazonfrik_order)
    {
        // 1. Intentar desde la tabla local
        $sql = '
            SELECT 
                amazon_order_item_id AS orderItemId,
                quantity
            FROM `' . _DB_PREFIX_ . 'amazonfrik_order_detail`
            WHERE id_amazonfrik_order = ' . (int) $id_amazonfrik_order;

        $items = Db::getInstance()->executeS($sql);

        if (!empty($items)) {
            foreach ($items as &$item) {
                $item['quantity'] = (int) $item['quantity'];
            }
            return $items;
        }

        // 2. Fallback: llamar a la API de Amazon
        try {
            $client = new AmazonSpApiClient();
            $path = '/orders/v0/orders/' . urlencode($amazon_order_id) . '/orderItems';
            $response = $client->call('GET', $path);

            if (!isset($response['payload']['OrderItems']) || !is_array($response['payload']['OrderItems'])) {
                return [];
            }

            $api_items = [];
            $order_items = $response['payload']['OrderItems'];

            foreach ($order_items as $item) {
                // Preparar datos para el retorno (solo lo necesario para el envío)
                $api_items[] = [
                    'orderItemId' => $item['OrderItemId'],
                    'quantity' => (int) $item['QuantityOrdered']
                ];

                // Preparar datos completos para guardar en BD
                $asin = isset($item['ASIN']) ? pSQL($item['ASIN']) : null;
                $seller_sku = isset($item['SellerSKU']) ? pSQL($item['SellerSKU']) : null;
                $product_name = isset($item['Title']) ? pSQL($item['Title']) : null;
                $quantity = (int) $item['QuantityOrdered'];
                $item_price = isset($item['ItemPrice']['Amount']) ? (float) $item['ItemPrice']['Amount'] : null;
                $item_tax = isset($item['ItemTax']['Amount']) ? (float) $item['ItemTax']['Amount'] : null;

                // Buscar id_product e id_product_attribute (opcional, para futuro)
                $id_product = null;
                $id_product_attribute = null;
                if ($seller_sku) {
                    $product_sql = '
                            SELECT 
                                p.id_product,
                                COALESCE(pa.id_product_attribute, 0) as id_product_attribute
                            FROM `' . _DB_PREFIX_ . 'product` p
                            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute` pa 
                                ON p.id_product = pa.id_product AND pa.reference = "' . pSQL($seller_sku) . '"
                            WHERE p.reference = "' . pSQL($seller_sku) . '"
                            OR pa.reference = "' . pSQL($seller_sku) . '"                    
                        ';
                    $product = Db::getInstance()->getRow($product_sql);
                    if ($product) {
                        $id_product = (int) $product['id_product'];
                        $id_product_attribute = (int) $product['id_product_attribute'];
                    }
                }

                // Insertar en la tabla local
                Db::getInstance()->insert(
                    'amazonfrik_order_detail',
                    [
                        'id_amazonfrik_order' => (int) $id_amazonfrik_order,
                        'amazon_order_item_id' => pSQL($item['OrderItemId']),
                        'asin' => $asin,
                        'seller_sku' => $seller_sku,
                        'prestashop_sku' => $seller_sku, // en tu caso son iguales
                        'id_product' => $id_product,
                        'id_product_attribute' => $id_product_attribute,
                        'product_name' => $product_name,
                        'quantity' => $quantity,
                        'item_price' => $item_price,
                        'item_tax' => $item_tax
                    ]
                );
            }

            return $api_items;
        } catch (Exception $e) {
            error_log('[AmazonFrik] Error al obtener orderItems desde API: ' . $e->getMessage());
            return [];
        }
    }


}