<?php

require_once __DIR__ . '/MediaMtxPathConfigClient.php';

class MediaMtxClient
{
    private static $instance = null;

    private $pathConfigClient;

    private function __construct($apiUrl = null)
    {
        $this->pathConfigClient = MediaMtxPathConfigClient::getInstance($apiUrl);
    }

    public static function getInstance($apiUrl = null)
    {
        if (self::$instance === null) {
            self::$instance = new self($apiUrl);
        }

        return self::$instance;
    }

    public function addPath(string $pathName, string $sourceUrl, bool $record = false): array
    {
        return $this->ensurePathConfig($pathName, [
            'source' => $sourceUrl,
            'record' => $record
        ]);
    }

    public function ensurePathConfig(string $pathName, array $expectedConfig): array
    {
        $name = trim($pathName);
        $source = trim((string) ($expectedConfig['source'] ?? ''));

        if ($name === '') {
            return [
                'success' => false,
                'code' => 400,
                'data' => 'pathName is required'
            ];
        }

        if ($source === '') {
            return [
                'success' => false,
                'code' => 400,
                'data' => 'sourceUrl is required'
            ];
        }

        $recordEnabled = intval($expectedConfig['record'] ?? 0) === 1;
        $recordPath = trim((string) ($expectedConfig['recordPath'] ?? ''));
        $recordFormat = trim((string) ($expectedConfig['recordFormat'] ?? 'fmp4'));
        $recordPartDuration = intval($expectedConfig['recordPartDuration'] ?? 60);
        if ($recordPartDuration <= 0) {
            $recordPartDuration = 60;
        }

        $body = [
            'source' => $source,
            'record' => $recordEnabled
        ];
        if ($recordEnabled) {
            if ($recordPath !== '') {
                $body['recordPath'] = $recordPath;
            }
            if ($recordFormat !== '') {
                $body['recordFormat'] = $recordFormat;
            }
            $body['recordPartDuration'] = $recordPartDuration . 's';
        }

        $getResult = $this->pathConfigClient->getPathConfig($name);
        if (!$getResult['success']) {
            return [
                'success' => false,
                'code' => intval($getResult['code'] ?? 500),
                'data' => [
                    'message' => 'failed to query existing MediaMTX path config',
                    'error' => $getResult['data']
                ]
            ];
        }

        if (!empty($getResult['exists'])) {
            $current = $this->normalizeConfig($getResult['data']);
            $mismatches = $this->detectMismatches($current, [
                'source' => $source,
                'record' => $recordEnabled,
                'recordPath' => $recordPath,
                'recordFormat' => $recordFormat,
                'recordPartDuration' => $recordPartDuration
            ]);

            if (!empty($mismatches)) {
                $syncResult = $this->pathConfigClient->addPathConfig($name, $body);
                if ($syncResult['success']) {
                    return [
                        'success' => true,
                        'code' => intval($syncResult['code'] ?? 200),
                        'data' => [
                            'exists' => true,
                            'updated' => true,
                            'mismatches' => $mismatches,
                            'config' => $syncResult['data']
                        ]
                    ];
                }

                return [
                    'success' => false,
                    'code' => intval($syncResult['code'] ?? 409),
                    'data' => [
                        'message' => 'existing MediaMTX path config is inconsistent and sync failed',
                        'mismatches' => $mismatches,
                        'currentConfig' => $current,
                        'syncError' => $syncResult['data']
                    ]
                ];
            }

            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'exists' => true,
                    'config' => $current
                ]
            ];
        }

        $addResult = $this->pathConfigClient->addPathConfig($name, $body);
        if (!$addResult['success']) {
            return [
                'success' => false,
                'code' => intval($addResult['code'] ?? 500),
                'data' => [
                    'message' => 'failed to add MediaMTX path config',
                    'error' => $addResult['data']
                ]
            ];
        }

        return [
            'success' => true,
            'code' => intval($addResult['code'] ?? 201),
            'data' => [
                'exists' => false,
                'created' => true,
                'config' => $addResult['data']
            ]
        ];
    }

    public function checkPathExists(string $pathName): array
    {
        $name = trim($pathName);
        if ($name === '') {
            return [
                'success' => true,
                'code' => 200,
                'exists' => false,
                'data' => null
            ];
        }

        $listResult = $this->pathConfigClient->listPathConfigs();
        if (is_array($listResult) && !empty($listResult['success'])) {
            return [
                'success' => true,
                'code' => intval($listResult['code'] ?? 200),
                'exists' => $this->pathExistsInListPayload($name, $listResult['data'] ?? []),
                'data' => $listResult['data'] ?? null
            ];
        }

        $result = $this->pathConfigClient->getPathConfig($name);
        if (!$result['success']) {
            return [
                'success' => false,
                'code' => intval($result['code'] ?? 500),
                'exists' => false,
                'data' => $result['data'] ?? 'failed to query MediaMTX path config'
            ];
        }

        return [
            'success' => true,
            'code' => intval($result['code'] ?? 200),
            'exists' => !empty($result['exists']),
            'data' => $result['data'] ?? null
        ];
    }

    public function removePath(string $pathName): array
    {
        $name = trim($pathName);
        if ($name === '') {
            return [
                'success' => false,
                'code' => 400,
                'data' => 'pathName is required'
            ];
        }

        $result = $this->pathConfigClient->deletePathConfig($name);
        if (!is_array($result) || empty($result['success'])) {
            return [
                'success' => false,
                'code' => intval($result['code'] ?? 500),
                'data' => $result['data'] ?? 'failed to remove MediaMTX path config'
            ];
        }

        return [
            'success' => true,
            'code' => intval($result['code'] ?? 200),
            'data' => $result['data'] ?? 'removed'
        ];
    }

    public function getPathList(): array
    {
        return [
            'success' => false,
            'code' => 501,
            'data' => 'getPathList is not implemented for /get/{name} API mode'
        ];
    }

    private function normalizeConfig($config): array
    {
        if (!is_array($config)) {
            return [];
        }

        if (isset($config['conf']) && is_array($config['conf'])) {
            return $config['conf'];
        }

        return $config;
    }

    private function detectMismatches(array $current, array $expected): array
    {
        $mismatches = [];

        if (array_key_exists('source', $current)) {
            $currentSource = trim((string) $current['source']);
            if ($currentSource !== '' && strcasecmp($currentSource, (string) $expected['source']) !== 0) {
                $mismatches[] = [
                    'field' => 'source',
                    'expected' => $expected['source'],
                    'actual' => $currentSource
                ];
            }
        }

        if (array_key_exists('record', $current)) {
            $currentRecord = filter_var($current['record'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($currentRecord !== null && $currentRecord !== (bool) $expected['record']) {
                $mismatches[] = [
                    'field' => 'record',
                    'expected' => (bool) $expected['record'],
                    'actual' => $currentRecord
                ];
            }
        }

        if ((bool) $expected['record']) {
            if (array_key_exists('recordPath', $current) && $expected['recordPath'] !== '') {
                $currentPath = trim((string) $current['recordPath']);
                if ($currentPath !== '' && $currentPath !== $expected['recordPath']) {
                    $mismatches[] = [
                        'field' => 'recordPath',
                        'expected' => $expected['recordPath'],
                        'actual' => $currentPath
                    ];
                }
            }

            if (array_key_exists('recordFormat', $current) && $expected['recordFormat'] !== '') {
                $currentFormat = strtolower(trim((string) $current['recordFormat']));
                $expectedFormat = strtolower(trim((string) $expected['recordFormat']));
                if ($currentFormat !== '' && $currentFormat !== $expectedFormat) {
                    $mismatches[] = [
                        'field' => 'recordFormat',
                        'expected' => $expected['recordFormat'],
                        'actual' => $current['recordFormat']
                    ];
                }
            }

            if (array_key_exists('recordPartDuration', $current)) {
                $currentDuration = $this->toDurationSeconds($current['recordPartDuration']);
                if ($currentDuration !== null && intval($expected['recordPartDuration']) > 0 && $currentDuration !== intval($expected['recordPartDuration'])) {
                    $mismatches[] = [
                        'field' => 'recordPartDuration',
                        'expected' => intval($expected['recordPartDuration']),
                        'actual' => $current['recordPartDuration']
                    ];
                }
            }
        }

        return $mismatches;
    }

    private function toDurationSeconds($value)
    {
        if (is_int($value) || is_float($value)) {
            $sec = intval($value);
            return $sec > 0 ? $sec : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $sec = intval($raw);
            return $sec > 0 ? $sec : null;
        }

        if (preg_match('/^(\d+)\s*s$/i', $raw, $match)) {
            $sec = intval($match[1]);
            return $sec > 0 ? $sec : null;
        }

        return null;
    }

    private function pathExistsInListPayload(string $pathName, $payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        if (array_key_exists($pathName, $payload)) {
            return true;
        }

        $candidateLists = [];
        if (isset($payload['items'])) {
            $candidateLists[] = $payload['items'];
        }
        if (isset($payload['paths'])) {
            $candidateLists[] = $payload['paths'];
        }
        $candidateLists[] = $payload;

        foreach ($candidateLists as $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $key => $item) {
                if (is_string($key) && $key === $pathName) {
                    return true;
                }
                if (is_string($item) && $item === $pathName) {
                    return true;
                }
                if (is_array($item)) {
                    $itemName = isset($item['name']) ? trim((string) $item['name']) : '';
                    if ($itemName === $pathName) {
                        return true;
                    }
                    $itemPathName = isset($item['pathName']) ? trim((string) $item['pathName']) : '';
                    if ($itemPathName === $pathName) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
