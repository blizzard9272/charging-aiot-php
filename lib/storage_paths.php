<?php

function storage_path_definitions()
{
    return array(
        'raw_upload' => array(
            'name' => '原始上传包',
            'description' => '上传接口接收到的整包原始二进制流，未做拆帧，用于回放与问题排查。',
            'default_template' => 'storage/{date}/protocol_{protocol}/{camera}/raw'
        ),
        'frame' => array(
            'name' => '协议帧',
            'description' => '从整包中拆分出的单帧协议二进制，包含帧头、帧尾和校验信息。',
            'default_template' => 'storage/{date}/protocol_{protocol}/{camera}/frame'
        ),
        'payload' => array(
            'name' => '业务载荷',
            'description' => '协议帧中的业务载荷二进制，例如 102 向量载荷和 103 媒体分片载荷。',
            'default_template' => 'storage/{date}/protocol_{protocol}/{camera}/payload'
        ),
        'image' => array(
            'name' => '103 媒体文件',
            'description' => '103 协议解析出的最终媒体文件，既可能是图片，也可能是视频，因此目录统一命名为 media。',
            'default_template' => 'storage/{date}/protocol_{protocol}/{camera}/media'
        ),
        'embedding' => array(
            'name' => '102 向量文件',
            'description' => '102 协议拆出的特征向量文件，按批次单独分目录保存，便于排查和迁移。',
            'default_template' => 'storage/{date}/protocol_{protocol}/{camera}/vector/batch_{batch}'
        )
    );
}

function storage_normalize_template($template)
{
    $text = str_replace('\\', '/', trim((string) $template));
    $text = preg_replace('#/+#', '/', $text);
    $text = trim((string) $text, '/');
    return $text;
}

function storage_validate_template($category, $template, &$message = '')
{
    $normalized = storage_normalize_template($template);
    if ($normalized === '') {
        $message = 'path template is empty';
        return false;
    }
    if (preg_match('/^\w+:/', $normalized) === 1 || strpos($normalized, '../') !== false || strpos($normalized, '/..') !== false) {
        $message = 'path template must be relative to charging-aiot-php and cannot contain ..';
        return false;
    }
    if (strpos($normalized, '{date}') === false || strpos($normalized, '{protocol}') === false || strpos($normalized, '{camera}') === false) {
        $message = 'path template must include {date}, {protocol}, {camera}';
        return false;
    }
    if ($category === 'embedding' && strpos($normalized, '{batch}') === false) {
        $message = 'embedding template must include {batch}';
        return false;
    }
    return true;
}

function storage_ensure_settings_table(PDO $pdo)
{
    $sql = "CREATE TABLE IF NOT EXISTS storage_path_settings (
      id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      category_key VARCHAR(32) NOT NULL,
      path_template VARCHAR(255) NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uk_category_key (category_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
}

function storage_bootstrap_settings(PDO $pdo)
{
    storage_ensure_settings_table($pdo);
    $defs = storage_path_definitions();
    $stmt = $pdo->prepare('INSERT INTO storage_path_settings (category_key, path_template) VALUES (?, ?) ON DUPLICATE KEY UPDATE category_key = category_key');
    foreach ($defs as $key => $meta) {
        $stmt->execute(array($key, $meta['default_template']));
    }
}

function storage_get_settings(PDO $pdo, $forceRefresh = false)
{
    static $cache = null;
    if ($cache !== null && !$forceRefresh) {
        return $cache;
    }

    storage_bootstrap_settings($pdo);
    $defs = storage_path_definitions();
    $stmt = $pdo->query('SELECT category_key, path_template FROM storage_path_settings');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();

    $map = array();
    foreach ($defs as $key => $meta) {
        $map[$key] = storage_normalize_template($meta['default_template']);
    }
    foreach ($rows as $row) {
        $key = strval(isset($row['category_key']) ? $row['category_key'] : '');
        if (isset($defs[$key])) {
            $map[$key] = storage_normalize_template(isset($row['path_template']) ? $row['path_template'] : '');
        }
    }

    $cache = $map;
    return $map;
}

function storage_update_settings(PDO $pdo, $settings)
{
    if (!is_array($settings)) {
        throw new InvalidArgumentException('settings must be an array');
    }
    $defs = storage_path_definitions();
    $normalized = array();
    foreach ($settings as $row) {
        if (!is_array($row)) continue;
        $key = strval(isset($row['category_key']) ? $row['category_key'] : '');
        $template = isset($row['path_template']) ? $row['path_template'] : '';
        if (!isset($defs[$key])) {
            continue;
        }
        $msg = '';
        if (!storage_validate_template($key, $template, $msg)) {
            throw new InvalidArgumentException('invalid template for ' . $key . ': ' . $msg);
        }
        $normalized[$key] = storage_normalize_template($template);
    }
    if (empty($normalized)) {
        throw new InvalidArgumentException('no valid settings to update');
    }

    storage_bootstrap_settings($pdo);
    $stmt = $pdo->prepare('UPDATE storage_path_settings SET path_template = ?, updated_at = CURRENT_TIMESTAMP WHERE category_key = ?');
    foreach ($normalized as $key => $template) {
        $stmt->execute(array($template, $key));
    }
    storage_get_settings($pdo, true);
}

function storage_render_template($template, $vars)
{
    $map = array(
        '{date}' => strval(isset($vars['date']) ? $vars['date'] : ''),
        '{protocol}' => strval(isset($vars['protocol']) ? $vars['protocol'] : ''),
        '{camera}' => strval(isset($vars['camera']) ? $vars['camera'] : ''),
        '{batch}' => strval(isset($vars['batch']) ? $vars['batch'] : '0')
    );
    $result = strtr(strval($template), $map);
    return storage_normalize_template($result);
}

function storage_resolve_relative_dir(PDO $pdo, $category, $vars)
{
    $settings = storage_get_settings($pdo);
    $defs = storage_path_definitions();
    if (!isset($defs[$category])) {
        throw new InvalidArgumentException('unknown storage category: ' . $category);
    }
    $template = isset($settings[$category]) ? $settings[$category] : storage_normalize_template($defs[$category]['default_template']);
    return storage_render_template($template, $vars);
}

function storage_relative_file_path($relativeDir, $filename)
{
    $dir = trim(storage_normalize_template($relativeDir), '/');
    return 'charging-aiot-php/' . $dir . '/' . ltrim(strval($filename), '/');
}

function storage_php_root()
{
    return dirname(__DIR__);
}

function storage_project_root()
{
    return dirname(__DIR__, 2);
}

function storage_category_example_vars($category)
{
    if ($category === 'image') {
        return array('date' => date('Ymd'), 'protocol' => '103', 'camera' => 'cam5', 'batch' => '1');
    }
    if ($category === 'embedding') {
        return array('date' => date('Ymd'), 'protocol' => '102', 'camera' => 'cam5', 'batch' => '18');
    }
    return array('date' => date('Ymd'), 'protocol' => '101', 'camera' => 'cam5', 'batch' => '1');
}

function storage_examples(PDO $pdo)
{
    $defs = storage_path_definitions();
    $settings = storage_get_settings($pdo);
    $result = array();
    foreach ($defs as $key => $meta) {
        $template = isset($settings[$key]) ? $settings[$key] : $meta['default_template'];
        $vars = storage_category_example_vars($key);
        $exampleDir = storage_render_template($template, $vars);
        $defaultTemplate = storage_normalize_template($meta['default_template']);
        $result[] = array(
            'category_key' => $key,
            'name' => $meta['name'],
            'description' => $meta['description'],
            'path_template' => $template,
            'default_template' => $defaultTemplate,
            'is_recommended' => $template === $defaultTemplate ? 1 : 0,
            'example_path' => storage_relative_file_path($exampleDir, 'example.bin'),
            'placeholders' => $key === 'embedding'
                ? array('{date}', '{protocol}', '{camera}', '{batch}')
                : array('{date}', '{protocol}', '{camera}')
        );
    }
    return $result;
}
