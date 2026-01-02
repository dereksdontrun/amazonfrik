<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/AmazonSpApiClient.php';

class AmazonOrderImporter
{
    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Obtiene los datos principales de un pedido de Amazon
     *
     * @param string $amazonOrderId     
     * @return array|null
     */
    public function getOrderDataFromAmazon($amazonOrderId)
    {
        try {
            $client = new AmazonSpApiClient();
            $path = '/orders/v0/orders/' . urlencode($amazonOrderId);
            $response = $client->call('GET', $path);

            if (!isset($response['payload'])) {
                return null;
            }

            $payload = $response['payload'];
            return [
                'ship_service_level' => isset($payload['ShipServiceLevel']) ? pSQL($payload['ShipServiceLevel']) : null,
                'order_total' => isset($payload['OrderTotal']['Amount']) ? (float) $payload['OrderTotal']['Amount'] : null,
                'currency' => isset($payload['OrderTotal']['CurrencyCode']) ? pSQL($payload['OrderTotal']['CurrencyCode']) : null,
                'purchase_date' => isset($payload['PurchaseDate']) ? $payload['PurchaseDate'] : null,
            ];
        } catch (Exception $e) {
            error_log('[AmazonFrik] Error al obtener datos del pedido: ' . $e->getMessage());
            return null;
        }
    }
    
}