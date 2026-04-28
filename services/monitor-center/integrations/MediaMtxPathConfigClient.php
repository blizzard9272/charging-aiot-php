<?php

class MediaMtxPathConfigClient
{
    private static $instance = null;

    private $baseUrl = 'http://172.18.7.124:9997/v3/config/paths';

    private function __construct($baseUrl = null)
    {
        $envBaseUrl = getenv('MEDIAMTX_PATH_CONFIG_API_URL');
        if (is_string($envBaseUrl) && trim($envBaseUrl) !== '') {
            $this->baseUrl = rtrim(trim($envBaseUrl), '/');
        }

        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            $this->baseUrl = rtrim(trim($baseUrl), '/');
        }
    }

    public static function getInstance($baseUrl = null)
    {
        if (self::$instance === null) {
            self::$instance = new self($baseUrl);
        }

        return self::$instance;
    }

    public function getPathConfig(string $pathName): array
    {
        $name = trim($pathName);
        if ($name === '') {
            return [
                'success' => false,
                'code' => 400,
                'exists' => false,
                'data' => 'pathName is required'
            ];
        }

        $result = $this->request('GET', $this->buildGetUrl($name));
        if ($result['success']) {
            return [
                'success' => true,
                'code' => $result['code'],
                'exists' => true,
                'data' => $this->unwrapPayload($result['data'])
            ];
        }

        if (intval($result['code']) === 404) {
            return [
                'success' => true,
                'code' => 404,
                'exists' => false,
                'data' => null
            ];
        }

        return [
            'success' => false,
            'code' => $result['code'],
            'exists' => false,
            'data' => $result['data']
        ];
    }

    public function addPathConfig(string $pathName, array $config): array
    {
        $name = trim($pathName);
        if ($name === '') {
            return [
                'success' => false,
                'code' => 400,
                'data' => 'pathName is required'
            ];
        }

        $result = $this->request('POST', $this->buildAddUrl($name), $config);

        if (!$result['success'] && intval($result['code']) === 405) {
            $result = $this->request('PUT', $this->buildAddUrl($name), $config);
        }

        return $result;
    }

    public function listPathConfigs(): array
    {
        $result = $this->request('GET', $this->baseUrl . '/list');
        if (!$result['success']) {
            return [
                'success' => false,
                'code' => intval($result['code'] ?? 500),
                'data' => $result['data'] ?? 'failed to list MediaMTX path configs'
            ];
        }

        return [
            'success' => true,
            'code' => intval($result['code'] ?? 200),
            'data' => $result['data'] ?? []
        ];
    }

    public function deletePathConfig(string $pathName): array
    {
        $name = trim($pathName);
        if ($name === '') {
            return [
                'success' => false,
                'code' => 400,
                'data' => 'pathName is required'
            ];
        }

        $candidates = [
            ['POST', $this->buildRemoveUrl($name)],
            ['DELETE', $this->buildRemoveUrl($name)],
            ['POST', $this->buildDeleteUrl($name)],
            ['DELETE', $this->buildDeleteUrl($name)]
        ];

        $last = [
            'success' => false,
            'code' => 500,
            'data' => 'failed to remove path config'
        ];

        foreach ($candidates as $candidate) {
            [$method, $url] = $candidate;
            $result = $this->request($method, $url);
            $last = $result;
            if (!empty($result['success'])) {
                return $result;
            }
            if (intval($result['code']) === 404) {
                return [
                    'success' => true,
                    'code' => 404,
                    'data' => 'path config not found'
                ];
            }
        }

        return $last;
    }

    private function buildGetUrl(string $pathName): string
    {
        return $this->baseUrl . '/get/' . rawurlencode($pathName);
    }

    private function buildAddUrl(string $pathName): string
    {
        return $this->baseUrl . '/add/' . rawurlencode($pathName);
    }

    private function buildRemoveUrl(string $pathName): string
    {
        return $this->baseUrl . '/remove/' . rawurlencode($pathName);
    }

    private function buildDeleteUrl(string $pathName): string
    {
        return $this->baseUrl . '/delete/' . rawurlencode($pathName);
    }

    private function unwrapPayload($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }

        if (array_key_exists('item', $payload)) {
            return $payload['item'];
        }

        if (array_key_exists('data', $payload)) {
            return $payload['data'];
        }

        return $payload;
    }

    private function request(string $method, string $url, ?array $payload = null): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return [
                'success' => false,
                'code' => 500,
                'data' => 'Failed to initialize cURL'
            ];
        }

        $headers = [
            'Accept: application/json'
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => $headers
        ];

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                curl_close($ch);
                return [
                    'success' => false,
                    'code' => 500,
                    'data' => 'Failed to encode request payload to JSON'
                ];
            }

            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = $json;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if ($response === false) {
            $errNo = curl_errno($ch);
            $errMsg = curl_error($ch);
            curl_close($ch);

            return [
                'success' => false,
                'code' => $errNo > 0 ? $errNo : 500,
                'data' => $errMsg !== '' ? $errMsg : 'Unknown cURL error'
            ];
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        $data = json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $response;

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'code' => $httpCode,
            'data' => $data
        ];
    }
}
