<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Cliente SP-API simplificado: solo usa x-amz-access-token (sin firma AWS)
 * Requiere: rol de IAM configurado correctamente en Amazon
 */
class AmazonSpApiClient
{
    private $credentials = null;
    private $access_token = null;

    // Endpoint según región
    private $endpoint = 'https://sellingpartnerapi-eu.amazon.com';

    public function __construct()
    {
        $this->loadCredentials();
    }

    private function loadCredentials()
    {
        $path = __DIR__ . '/../secrets/amazon_credentials.json';
        if (!file_exists($path)) {
            throw new Exception('Credenciales no encontradas: ' . $path);
        }
        $json = file_get_contents($path);
        $this->credentials = json_decode($json, true);
        if (!$this->credentials || !isset($this->credentials['client_id'])) {
            throw new Exception('Credenciales inválidas');
        }
    }

    private function ensureAccessToken()
    {
        if ($this->access_token) {
            return;
        }

        $url = 'https://api.amazon.com/auth/o2/token';
        $post_fields = http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'refresh_token' => $this->credentials['refresh_token']
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception('Error LWA Token: ' . $response);
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new Exception('Token inválido: ' . $response);
        }

        $this->access_token = $data['access_token'];
    }

    /**
     * Realiza una llamada a SP-API con x-amz-access-token
     */
    public function call($method, $path, $body = null)
    {
        $this->ensureAccessToken();

        $url = $this->endpoint . $path;
        $payload = $body ? json_encode($body) : null;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'x-amz-access-token: ' . $this->access_token,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        if ($payload) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('cURL error: ' . $error);
        }

        if ($http_code >= 400) {
            // Intentar extraer errors[0].code y errors[0].message (SP-API estándar)
            $code = null;
            $msg = null;

            $decoded = json_decode($response, true);
            if (is_array($decoded) && !empty($decoded['errors'][0])) {
                $code = isset($decoded['errors'][0]['code']) ? $decoded['errors'][0]['code'] : null;
                $msg = isset($decoded['errors'][0]['message']) ? $decoded['errors'][0]['message'] : null;
            }

            // Lanza un string estable: "HTTP=400 CODE=InvalidInput MSG=..." (sin clases nuevas)
            $parts = ['HTTP=' . $http_code];
            if ($code)
                $parts[] = 'CODE=' . $code;
            if ($msg)
                $parts[] = 'MSG=' . $msg;

            // Si no hay msg, usa raw response recortada
            if (!$msg) {
                $raw = is_string($response) ? $response : json_encode($response);
                $raw = (strlen($raw) > 500) ? substr($raw, 0, 500) . '...' : $raw;
                $parts[] = 'RAW=' . $raw;
            }

            throw new Exception(implode(' ', $parts));
        }

        return $response ? json_decode($response, true) : null;
    }
}