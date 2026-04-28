<?php

function storage_path_definitions()
{
    return array(
        'raw_upload' => array(
            'name' => '原始流归档',
            'description' => '上传接口收到的整包原始二进制流（未拆帧），用于问题追溯与回放。',
            'default_template' => 'storage/{date}/{protocol}/{camera}/raw_upload'
        ),
        'frame' => array(
            'name' => '协议帧(frame)',
            'description' => '从整包拆分后的单帧完整协议二进制（含帧头/帧尾/CRC）。',
            'default_template' => 'storage/{date}/{protocol}/{camera}/frame'
        ),
        'payload' => array(
            'name' => '业务载荷(payload)',
            'description' => '单帧中的业务负载二进制（例如 102 向量打包载荷、103 图片载荷原文）。',
            'default_template' => 'storage/{date}/{protocol}/{camera}/payload'
        ),
        'image' => array(
            'name' => '抓拍图片(image)',
            'description' => '103 payload 解出的 JPG 图片文件。',
            'default_template' => 'storage/{date}/{protocol}/{camera}/image'
        ),
        'embedding' => array(
            'name' => '102向量(embedding)',
            'description' => '102 单目标特征向量文件（通常 2048 字节，即 512 维 float32）。',
            'default_template' => 'storage/{date}/{protocol}/{camera}/embedding/batch_{batch}'
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

function storage_examples(PDO $pdo)
{
    $defs = storage_path_definitions();
    $settings = storage_get_settings($pdo);
    $result = array();
    foreach ($defs as $key => $meta) {
        $template = isset($settings[$key]) ? $settings[$key] : $meta['default_template'];
        $exampleDir = storage_render_template($template, array(
            'date' => date('Ymd'),
            'protocol' => '102',
            'camera' => 'cam1',
            'batch' => '1'
        ));
        $result[] = array(
            'category_key' => $key,
            'name' => $meta['name'],
            'description' => $meta['description'],
            'path_template' => $template,
            'example_path' => storage_relative_file_path($exampleDir, 'example.bin')
        );
    }
    return $result;
}

