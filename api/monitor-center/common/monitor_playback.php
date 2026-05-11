<?php

function sanitize_cover_text($name)
{
    $text = strtoupper(trim((string) $name));
    if ($text === '') {
        return 'VIDEO';
    }
    $text = preg_replace('/[^A-Z0-9]+/', '-', $text);
    if ($text === null) $text = 'VIDEO';
    $text = trim($text, '-');
    if ($text === '') {
        return 'VIDEO';
    }
    return substr($text, 0, 32);
}

function url_encode_path_segments($path)
{
    $segments = array();
    foreach (explode('/', str_replace('\\', '/', (string) $path)) as $segment) {
        if ($segment === '') continue;
        $segments[] = rawurlencode($segment);
    }
    return implode('/', $segments);
}

function parse_env_path_list($raw)
{
    $items = array();
    $text = trim((string) $raw);
    if ($text === '') return $items;
    $parts = preg_split('/[;\n\r]+/', $text);
    foreach ((array) $parts as $part) {
        $value = trim((string) $part);
        if ($value === '') continue;
        $items[] = $value;
    }
    return $items;
}

function read_runtime_setting($name)
{
    $key = trim((string) $name);
    if ($key === '') {
        return '';
    }

    if (isset($_SERVER[$key]) && trim((string) $_SERVER[$key]) !== '') {
        return trim((string) $_SERVER[$key]);
    }

    if (isset($_ENV[$key]) && trim((string) $_ENV[$key]) !== '') {
        return trim((string) $_ENV[$key]);
    }

    $value = getenv($key);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    return '';
}

function guess_desktop_video_dirs()
{
    $dirs = array();
    $userProfile = isset($_SERVER['USERPROFILE']) ? trim((string) $_SERVER['USERPROFILE']) : '';
    if ($userProfile === '' && isset($_ENV['USERPROFILE'])) {
        $userProfile = trim((string) $_ENV['USERPROFILE']);
    }
    if ($userProfile !== '') {
        $dirs[] = $userProfile . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'video';
        $dirs[] = $userProfile . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'videos';
        $dirs[] = $userProfile . DIRECTORY_SEPARATOR . 'OneDrive' . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'video';
        $dirs[] = $userProfile . DIRECTORY_SEPARATOR . 'OneDrive' . DIRECTORY_SEPARATOR . 'Desktop' . DIRECTORY_SEPARATOR . 'videos';
    }
    return $dirs;
}

function guess_linux_video_dirs()
{
    $dirs = array(
        '/home/zjl/Desktop/videos',
        '/home/zjl/Desktop/video',
        '/home/zjl/OneDrive/Desktop/videos',
        '/home/zjl/OneDrive/Desktop/video'
    );
    return $dirs;
}

function build_playback_scan_roots()
{
    $projectRoot = dirname(__DIR__, 3);
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\') : '';
    $roots = array();

    $envRoots = parse_env_path_list(read_runtime_setting('PLAYBACK_VIDEO_ROOTS'));
    foreach ($envRoots as $rootPath) {
        $roots[] = array('dir' => $rootPath, 'urlPrefix' => 'videos');
    }

    $defaultDirs = array(
        $docRoot === '' ? '' : ($docRoot . DIRECTORY_SEPARATOR . 'videos'),
        $projectRoot . DIRECTORY_SEPARATOR . 'videos',
        $projectRoot . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . 'videos'
    );
    foreach (guess_desktop_video_dirs() as $desktopDir) {
        $defaultDirs[] = $desktopDir;
    }

    foreach (guess_linux_video_dirs() as $linuxDir) {
        $defaultDirs[] = $linuxDir;
    }

    foreach ($defaultDirs as $dir) {
        $roots[] = array('dir' => $dir, 'urlPrefix' => 'videos');
    }

    return $roots;
}

function normalize_http_base_url($url)
{
    $base = trim((string) $url);
    if ($base === '') {
        return '';
    }
    if (!preg_match('/^https?:\/\//i', $base)) {
        return '';
    }
    return rtrim($base, '/') . '/';
}

function get_request_origin()
{
    $originHeader = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
    if ($originHeader !== '' && preg_match('/^https?:\/\/[^\/]+$/i', $originHeader)) {
        return $originHeader;
    }

    $host = '';
    if (isset($_SERVER['HTTP_HOST']) && trim((string) $_SERVER['HTTP_HOST']) !== '') {
        $host = trim((string) $_SERVER['HTTP_HOST']);
    } elseif (isset($_SERVER['SERVER_NAME']) && trim((string) $_SERVER['SERVER_NAME']) !== '') {
        $host = trim((string) $_SERVER['SERVER_NAME']);
        $port = isset($_SERVER['SERVER_PORT']) ? intval($_SERVER['SERVER_PORT']) : 0;
        if ($port > 0 && $port !== 80 && $port !== 443) {
            $host .= ':' . $port;
        }
    }

    if ($host === '') {
        return '';
    }

    $scheme = 'http';
    if (!empty($_SERVER['REQUEST_SCHEME'])) {
        $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']);
    } elseif (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    } elseif (isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443) {
        $scheme = 'https';
    }

    return $scheme . '://' . $host;
}

function fetch_http_text($url, $timeoutSeconds = 5)
{
    $target = trim((string) $url);
    if ($target === '') {
        return null;
    }

    $timeout = intval($timeoutSeconds);
    if ($timeout <= 0) {
        $timeout = 5;
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "Connection: close\r\nUser-Agent: charging-platform/1.0\r\n"
        )
    ));

    $content = @file_get_contents($target, false, $context);
    if (is_string($content) && $content !== '') {
        return $content;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($target);
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: close', 'User-Agent: charging-platform/1.0'));
            $fallback = curl_exec($ch);
            curl_close($ch);
            if (is_string($fallback) && $fallback !== '') {
                return $fallback;
            }
        }
    }

    return null;
}

function resolve_http_url($baseUrl, $href)
{
    $base = normalize_http_base_url($baseUrl);
    $link = trim((string) $href);
    if ($base === '' || $link === '') {
        return '';
    }

    $hashPos = strpos($link, '#');
    if ($hashPos !== false) {
        $link = substr($link, 0, $hashPos);
    }
    $queryPos = strpos($link, '?');
    if ($queryPos !== false) {
        $link = substr($link, 0, $queryPos);
    }
    if ($link === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $link)) {
        return $link;
    }
    if (strpos($link, '//') === 0) {
        $scheme = parse_url($base, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            $scheme = 'http';
        }
        return $scheme . ':' . $link;
    }

    $baseParts = parse_url($base);
    if ($baseParts === false) {
        return '';
    }
    $scheme = isset($baseParts['scheme']) ? $baseParts['scheme'] : 'http';
    $host = isset($baseParts['host']) ? $baseParts['host'] : '';
    $port = isset($baseParts['port']) ? ':' . intval($baseParts['port']) : '';
    if ($host === '') {
        return '';
    }
    $origin = $scheme . '://' . $host . $port;

    if (strpos($link, '/') === 0) {
        return $origin . $link;
    }

    return $base . $link;
}

function try_parse_http_index_row_meta($rawTail)
{
    $meta = array(
        'recordTimeMs' => null,
        'fileSize' => null
    );

    $tail = html_entity_decode((string) $rawTail, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $tail = trim(preg_replace('/\s+/', ' ', strip_tags($tail)));
    if ($tail === '') {
        return $meta;
    }

    $timeCandidates = array();
    if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?)/', $tail, $m)) {
        $timeCandidates[] = $m[1];
    }
    if (preg_match('/(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}(?::\d{2})?)/', $tail, $m)) {
        $timeCandidates[] = $m[1];
    }
    if (preg_match('/(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}(?::\d{2})?)/', $tail, $m)) {
        $timeCandidates[] = $m[1];
    }
    foreach ($timeCandidates as $candidate) {
        $ts = strtotime($candidate);
        if ($ts !== false && $ts > 0) {
            $meta['recordTimeMs'] = intval($ts) * 1000;
            break;
        }
    }

    if (preg_match('/\b(\d+(?:\.\d+)?)\s*([KMGT]?)(?:i?B)?\s*$/i', $tail, $m)) {
        $num = floatval($m[1]);
        $unit = strtoupper((string) $m[2]);
        $factor = 1;
        if ($unit === 'K') $factor = 1024;
        if ($unit === 'M') $factor = 1024 * 1024;
        if ($unit === 'G') $factor = 1024 * 1024 * 1024;
        if ($unit === 'T') $factor = 1024 * 1024 * 1024 * 1024;
        $meta['fileSize'] = intval(round($num * $factor));
        return $meta;
    }

    if (preg_match('/\s(\d{2,})\s*$/', $tail, $m)) {
        $meta['fileSize'] = intval($m[1]);
    }

    return $meta;
}

function parse_http_index_entries($html)
{
    $entries = array();
    if (!is_string($html) || trim($html) === '') {
        return $entries;
    }

    if (!preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>([^<\r\n]*)/is', $html, $matches, PREG_SET_ORDER)) {
        return $entries;
    }

    foreach ($matches as $row) {
        $hrefRaw = isset($row[1]) ? html_entity_decode((string) $row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $nameRaw = isset($row[2]) ? html_entity_decode((string) $row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $tail = isset($row[3]) ? (string) $row[3] : '';

        $href = trim($hrefRaw);
        if ($href === '' || $href === './' || $href === '../') {
            continue;
        }
        if (stripos($href, 'javascript:') === 0 || stripos($href, 'mailto:') === 0) {
            continue;
        }

        $isDir = substr($href, -1) === '/';
        $name = trim(strip_tags($nameRaw));
        if ($name === '') {
            $name = trim($href, '/');
        }

        $meta = try_parse_http_index_row_meta($tail);
        $entries[] = array(
            'href' => $href,
            'name' => $name,
            'isDir' => $isDir,
            'recordTimeMs' => $meta['recordTimeMs'],
            'fileSize' => $meta['fileSize']
        );
    }

    return $entries;
}

function build_record_time_from_name($filename)
{
    $name = (string) $filename;
    if (preg_match('/(^|[^0-9])(1[0-9]{9})([^0-9]|$)/', $name, $m)) {
        $ts = intval($m[2]);
        if ($ts > 0) return $ts * 1000;
    }
    if (preg_match('/(^|[^0-9])(1[0-9]{12})([^0-9]|$)/', $name, $m)) {
        $tsMs = intval($m[2]);
        if ($tsMs > 0) return $tsMs;
    }
    return null;
}

function increment_top_level_counter(&$bucket, $relativePath)
{
    if (!is_array($bucket)) {
        $bucket = array();
    }

    $relative = str_replace('\\', '/', (string) $relativePath);
    $relative = ltrim($relative, '/');
    if ($relative === '') {
        return;
    }

    $segments = explode('/', $relative);
    $top = isset($segments[0]) ? trim((string) $segments[0]) : '';
    if ($top === '') {
        $top = '__root__';
    }

    if (!isset($bucket[$top])) {
        $bucket[$top] = 0;
    }
    $bucket[$top] += 1;
}

function add_playback_http_records_recursive($rootBase, $currentUrl, $relativeDir, &$records, &$seenKeys, &$visitedDirs, $depth = 0, &$debugState = null)
{
    if ($depth > 10) {
        return;
    }

    $current = normalize_http_base_url($currentUrl);
    $root = normalize_http_base_url($rootBase);
    if ($current === '' || $root === '') {
        return;
    }
    if (strpos($current, $root) !== 0) {
        return;
    }
    if (isset($visitedDirs[$current])) {
        return;
    }
    $visitedDirs[$current] = true;
    if (is_array($debugState)) {
        if (!isset($debugState['visitedDirs']) || !is_array($debugState['visitedDirs'])) {
            $debugState['visitedDirs'] = array();
        }
        $debugState['visitedDirs'][] = array(
            'url' => $current,
            'depth' => $depth,
            'relativeDir' => $relativeDir
        );
    }

    $html = fetch_http_text($current, 6);
    if (!is_string($html) || trim($html) === '') {
        if (is_array($debugState)) {
            if (!isset($debugState['emptyHttpResponses']) || !is_array($debugState['emptyHttpResponses'])) {
                $debugState['emptyHttpResponses'] = array();
            }
            $debugState['emptyHttpResponses'][] = $current;
        }
        return;
    }

    $entries = parse_http_index_entries($html);
    if (is_array($debugState) && $depth === 0) {
        $debugState['rootHttpEntries'] = array();
        foreach ($entries as $entry) {
            $debugState['rootHttpEntries'][] = array(
                'href' => isset($entry['href']) ? $entry['href'] : '',
                'name' => isset($entry['name']) ? $entry['name'] : '',
                'isDir' => !empty($entry['isDir'])
            );
        }
    }
    foreach ($entries as $entry) {
        $resolved = resolve_http_url($current, isset($entry['href']) ? $entry['href'] : '');
        if ($resolved === '' || strpos($resolved, $root) !== 0) {
            continue;
        }

        $resolvedNoQuery = preg_replace('/[?#].*$/', '', $resolved);
        $relative = ltrim(substr($resolvedNoQuery, strlen($root)), '/');
        if ($relative === '') {
            continue;
        }

        $isDir = !empty($entry['isDir']);
        if ($isDir) {
            if (is_array($debugState)) {
                if (!isset($debugState['httpDirHits']) || !is_array($debugState['httpDirHits'])) {
                    $debugState['httpDirHits'] = array();
                }
                increment_top_level_counter($debugState['httpDirHits'], $relative);
            }
            add_playback_http_records_recursive($root, $resolved, $relative, $records, $seenKeys, $visitedDirs, $depth + 1, $debugState);
            continue;
        }

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($ext, array('mp4', 'mov', 'm4v', 'webm', 'm3u8'), true)) {
            if (is_array($debugState)) {
                if (!isset($debugState['skippedHttpFiles']) || !is_array($debugState['skippedHttpFiles'])) {
                    $debugState['skippedHttpFiles'] = array();
                }
                if (!isset($debugState['httpSkippedByTopLevel']) || !is_array($debugState['httpSkippedByTopLevel'])) {
                    $debugState['httpSkippedByTopLevel'] = array();
                }
                increment_top_level_counter($debugState['httpSkippedByTopLevel'], $relative);
                if (count($debugState['skippedHttpFiles']) < 50) {
                    $debugState['skippedHttpFiles'][] = array(
                        'path' => $relative,
                        'extension' => $ext
                    );
                }
            }
            continue;
        }

        $relativeKey = strtolower($relative);
        if (isset($seenKeys[$relativeKey])) {
            continue;
        }
        $seenKeys[$relativeKey] = true;

        $filename = pathinfo($relative, PATHINFO_FILENAME);
        $recordTimeMs = isset($entry['recordTimeMs']) ? intval($entry['recordTimeMs']) : 0;
        if ($recordTimeMs <= 0) {
            $recordTimeMs = intval(build_record_time_from_name($filename));
        }
        if ($recordTimeMs <= 0) {
            $recordTimeMs = intval(time()) * 1000;
        }
        if (is_array($debugState)) {
            if (!isset($debugState['httpAcceptedByTopLevel']) || !is_array($debugState['httpAcceptedByTopLevel'])) {
                $debugState['httpAcceptedByTopLevel'] = array();
            }
            increment_top_level_counter($debugState['httpAcceptedByTopLevel'], $relative);
        }

        $records[] = array(
            'id' => abs(crc32($relative)) ?: (count($records) + 1),
            'name' => str_replace('_', ' ', $filename),
            'recordTime' => date('Y-m-d H:i:s', intval($recordTimeMs / 1000)),
            'recordTimeMs' => $recordTimeMs,
            'coverText' => sanitize_cover_text($filename),
            'videoUrl' => $resolvedNoQuery,
            'videoPath' => str_replace('\\', '/', $relative),
            'fileSize' => isset($entry['fileSize']) ? intval($entry['fileSize']) : 0
        );
    }
}

function collect_playback_video_records()
{
    $debugEnabled = isset($_GET['debug']) && strval($_GET['debug']) === '1';
    $httpBase = read_runtime_setting('PLAYBACK_VIDEO_HTTP_BASE');
    if ($httpBase === '') {
        $origin = get_request_origin();
        if ($origin !== '') {
            $httpBase = $origin . '/videos/';
        } else {
            $httpBase = 'http://' . STREAM_SERVER_IP . '/videos/';
        }
    }
    $httpBase = rtrim($httpBase, '/') . '/';
    $candidateRoots = build_playback_scan_roots();
    $debugState = $debugEnabled ? array(
        'debugEnabled' => true,
        'settings' => array(
            'PLAYBACK_VIDEO_ROOTS' => read_runtime_setting('PLAYBACK_VIDEO_ROOTS'),
            'PLAYBACK_VIDEO_HTTP_BASE' => read_runtime_setting('PLAYBACK_VIDEO_HTTP_BASE')
        ),
        'httpBase' => $httpBase,
        'scanRoots' => array(),
        'usedLocalRoots' => array(),
        'localFilesByRoot' => array(),
        'localAcceptedByTopLevel' => array(),
        'rootHttpEntries' => array(),
        'visitedDirs' => array(),
        'httpDirHits' => array(),
        'httpAcceptedByTopLevel' => array(),
        'httpSkippedByTopLevel' => array(),
        'emptyHttpResponses' => array(),
        'skippedHttpFiles' => array()
    ) : null;
    if ($debugEnabled) {
        foreach ($candidateRoots as $rootItem) {
            $debugState['scanRoots'][] = isset($rootItem['dir']) ? (string) $rootItem['dir'] : '';
        }
    }

    $records = array();
    $seenKeys = array();
    foreach ($candidateRoots as $rootItem) {
        $dir = isset($rootItem['dir']) ? (string) $rootItem['dir'] : '';
        if ($dir === '' || !is_dir($dir)) {
            continue;
        }
        if ($debugEnabled) {
            $debugState['usedLocalRoots'][] = $dir;
            if (!isset($debugState['localFilesByRoot'][$dir])) {
                $debugState['localFilesByRoot'][$dir] = array();
            }
        }

        $base = rtrim(str_replace('\\', '/', $dir), '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!($fileInfo instanceof SplFileInfo) || !$fileInfo->isFile()) {
                continue;
            }

            $ext = strtolower((string) $fileInfo->getExtension());
            if (!in_array($ext, array('mp4', 'mov', 'm4v', 'webm', 'm3u8'), true)) {
                continue;
            }

            $fullPath = str_replace('\\', '/', $fileInfo->getPathname());
            if (strpos($fullPath, $base . '/') !== 0) {
                continue;
            }

            $relative = ltrim(substr($fullPath, strlen($base)), '/');
            if ($relative === '') continue;
            $relativeKey = strtolower($relative);
            if (isset($seenKeys[$relativeKey])) {
                continue;
            }
            $seenKeys[$relativeKey] = true;

            $filename = pathinfo($relative, PATHINFO_FILENAME);
            $timestampSec = intval($fileInfo->getMTime());
            if ($debugEnabled && count($debugState['localFilesByRoot'][$dir]) < 50) {
                $debugState['localFilesByRoot'][$dir][] = $relative;
            }
            if ($debugEnabled) {
                increment_top_level_counter($debugState['localAcceptedByTopLevel'], $relative);
            }
            $records[] = array(
                'id' => abs(crc32($relative)) ?: (count($records) + 1),
                'name' => str_replace('_', ' ', $filename),
                'recordTime' => date('Y-m-d H:i:s', $timestampSec > 0 ? $timestampSec : time()),
                'recordTimeMs' => ($timestampSec > 0 ? $timestampSec : time()) * 1000,
                'coverText' => sanitize_cover_text($filename),
                'videoUrl' => $httpBase . url_encode_path_segments($relative),
                'videoPath' => str_replace('\\', '/', $relative),
                'fileSize' => intval($fileInfo->getSize())
            );
        }
    }

    $visitedDirs = array();
    add_playback_http_records_recursive($httpBase, $httpBase, '', $records, $seenKeys, $visitedDirs, 0, $debugState);

    usort($records, function ($a, $b) {
        return intval($b['recordTimeMs']) - intval($a['recordTimeMs']);
    });
    return array(
        'records' => $records,
        'debug' => $debugState
    );
}

function handle_playback_list(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('Method not allowed', 405);
    }

    $page = normalize_int_or_null(isset($_GET['page']) ? $_GET['page'] : 1);
    $pageSize = normalize_int_or_null(isset($_GET['pageSize']) ? $_GET['pageSize'] : 20);
    if ($page === null || $page <= 0) $page = 1;
    if ($pageSize === null || $pageSize <= 0) $pageSize = 20;
    if ($pageSize > 200) $pageSize = 200;

    $keyword = strtolower(normalize_string(isset($_GET['videoName']) ? $_GET['videoName'] : ''));
    $startTimeMs = normalize_datetime_to_timestamp_ms(isset($_GET['startTime']) ? $_GET['startTime'] : null);
    $endTimeMs = normalize_datetime_to_timestamp_ms(isset($_GET['endTime']) ? $_GET['endTime'] : null);

    $collection = collect_playback_video_records();
    $all = isset($collection['records']) && is_array($collection['records']) ? $collection['records'] : array();
    $debugState = isset($collection['debug']) && is_array($collection['debug']) ? $collection['debug'] : null;
    $filtered = array();
    foreach ($all as $item) {
        $name = strtolower(isset($item['name']) ? (string) $item['name'] : '');
        $recordTimeMs = intval(isset($item['recordTimeMs']) ? $item['recordTimeMs'] : 0);
        if ($keyword !== '' && strpos($name, $keyword) === false) {
            continue;
        }
        if ($startTimeMs !== null && $recordTimeMs > 0 && $recordTimeMs < $startTimeMs) {
            continue;
        }
        if ($endTimeMs !== null && $recordTimeMs > 0 && $recordTimeMs > $endTimeMs) {
            continue;
        }
        unset($item['recordTimeMs']);
        $filtered[] = $item;
    }

    $total = count($filtered);
    $offset = ($page - 1) * $pageSize;
    $records = array_slice($filtered, $offset, $pageSize);

    $responseData = array(
        'total' => $total,
        'records' => array_values($records)
    );
    if ($debugState !== null) {
        $debugState['recordCountBeforeFilter'] = count($all);
        $debugState['recordCountAfterFilter'] = count($filtered);
        $debugState['sampleRecordPaths'] = array();
        foreach (array_slice($filtered, 0, 50) as $sampleItem) {
            $debugState['sampleRecordPaths'][] = isset($sampleItem['videoPath']) ? $sampleItem['videoPath'] : '';
        }
        $responseData['debug'] = $debugState;
    }

    response_success($responseData);
}

function resolve_cleanup_recordings_script_path()
{
    $envPath = getenv('CLEANUP_RECORDINGS_SCRIPT');
    if (is_string($envPath) && trim($envPath) !== '') {
        return trim($envPath);
    }

    return '/usr/local/bin/cleanup_mediamtx_records.sh';
}

function handle_cleanup_recordings(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        response_error('Method not allowed', 405);
    }

    $scriptPath = resolve_cleanup_recordings_script_path();
    if ($scriptPath === '' || !is_file($scriptPath)) {
        response_error('cleanup script not found: ' . $scriptPath, 500);
    }
    if (!is_executable($scriptPath)) {
        response_error('cleanup script is not executable: ' . $scriptPath, 500);
    }

    if (!function_exists('exec')) {
        response_error('php exec() is disabled', 500);
    }

    @set_time_limit(0);
    $output = array();
    $code = 1;
    $command = 'sudo ' . escapeshellarg($scriptPath) . ' 2>&1';
    exec($command, $output, $code);

    $tailLines = array_slice(array_values($output), -80);
    $payload = array(
        'scriptPath' => $scriptPath,
        'exitCode' => intval($code),
        'output' => $tailLines
    );

    write_device_audit($pdo, array(
        'event_type' => 'MAINTENANCE',
        'action_name' => 'cleanupRecordings',
        'result_status' => $code === 0 ? 1 : 0,
        'error_message' => $code === 0 ? null : 'cleanup script exit code: ' . intval($code),
        'request_payload' => array(
            'scriptPath' => $scriptPath
        ),
        'response_payload' => $payload
    ));

    if ($code !== 0) {
        response_error('cleanup script failed with exit code ' . intval($code), 500);
    }

    response_success($payload, 'Cleanup finished');
}
