<?php
require_once __DIR__ . '/storage_paths.php';

function storage_candidate_abs_paths($path)
{
    $text = trim(strval($path));
    if ($text === '') return array();
    $normalized = ltrim(str_replace('\\', '/', $text), '/');
    $phpRoot = storage_php_root();
    $projectRoot = storage_project_root();

    $candidates = array(
        $text,
        $projectRoot . '/' . $normalized,
        $phpRoot . '/' . $normalized
    );
    if (strpos($normalized, 'charging-aiot-php/') === 0) {
        $candidates[] = $projectRoot . '/' . substr($normalized, strlen('charging-aiot-php/'));
    }
    return array_values(array_unique($candidates));
}

function storage_safe_filename($path)
{
    return basename(str_replace('\\', '/', strval($path)));
}

function storage_move_file_if_needed($oldRelPath, $newRelPath, &$counter)
{
    if ($oldRelPath === '' || $newRelPath === '' || $oldRelPath === $newRelPath) {
        return;
    }

    $newAbs = storage_project_root() . '/' . ltrim(str_replace('\\', '/', $newRelPath), '/');
    $newDir = dirname($newAbs);
    if (!is_dir($newDir) && !@mkdir($newDir, 0777, true)) {
        $counter['errors'] += 1;
        return;
    }

    $src = null;
    foreach (storage_candidate_abs_paths($oldRelPath) as $candidate) {
        if (is_file($candidate)) {
            $src = $candidate;
            break;
        }
    }
    if ($src === null) {
        $counter['missing_files'] += 1;
        return;
    }

    if (realpath($src) === realpath($newAbs)) {
        return;
    }

    if (@rename($src, $newAbs)) {
        $counter['moved_files'] += 1;
        return;
    }
    if (@copy($src, $newAbs)) {
        @unlink($src);
        $counter['moved_files'] += 1;
        return;
    }
    $counter['errors'] += 1;
}

function storage_build_new_path(PDO $pdo, $category, $timestampMs, $protocol, $cameraId, $batchId, $oldPath)
{
    $filename = storage_safe_filename($oldPath);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return '';
    }
    $dir = storage_resolve_relative_dir($pdo, $category, array(
        'date' => date('Ymd', intval($timestampMs / 1000)),
        'protocol' => intval($protocol),
        'camera' => strval($cameraId),
        'batch' => intval($batchId)
    ));
    return storage_relative_file_path($dir, $filename);
}

function storage_rewrite_row_paths(PDO $pdo, $table, $protocol, $rows, &$counter)
{
    foreach ($rows as $row) {
        $id = intval($row['id']);
        $cameraId = strval($row['camera_id']);
        $timestamp = intval($row['event_timestamp_ms']);
        $batchId = intval(isset($row['batch_id']) ? $row['batch_id'] : 0);
        $normalized = json_decode(strval(isset($row['normalized_json']) ? $row['normalized_json'] : ''), true);
        if (!is_array($normalized)) {
            $normalized = array();
        }

        $changed = false;
        $updates = array();

        $rewriteNormalizedPath = function ($jsonKey, $category) use (&$normalized, &$changed, &$counter, $pdo, $timestamp, $protocol, $cameraId, $batchId) {
            $oldPath = strval(isset($normalized[$jsonKey]) ? $normalized[$jsonKey] : '');
            if ($oldPath === '') return;
            $newPath = storage_build_new_path($pdo, $category, $timestamp, $protocol, $cameraId, $batchId, $oldPath);
            if ($newPath === '') return;
            storage_move_file_if_needed($oldPath, $newPath, $counter);
            if ($newPath !== $oldPath) {
                $normalized[$jsonKey] = $newPath;
                $changed = true;
            }
        };

        $rewriteNormalizedPath('raw_file_path', 'raw_upload');
        $rewriteNormalizedPath('frame_file_path', 'frame');
        $rewriteNormalizedPath('payload_file_path', 'payload');
        $rewriteNormalizedPath('local_image_path', 'image');
        $rewriteNormalizedPath('embedding_file_path', 'embedding');

        if ($table === 'message_102_records') {
            $oldEmbeddingPath = strval(isset($row['embedding_file_path']) ? $row['embedding_file_path'] : '');
            if ($oldEmbeddingPath !== '') {
                $newEmbeddingPath = storage_build_new_path($pdo, 'embedding', $timestamp, 102, $cameraId, $batchId, $oldEmbeddingPath);
                if ($newEmbeddingPath !== '') {
                    storage_move_file_if_needed($oldEmbeddingPath, $newEmbeddingPath, $counter);
                    if ($newEmbeddingPath !== $oldEmbeddingPath) {
                        $updates['embedding_file_path'] = $newEmbeddingPath;
                        $changed = true;
                    }
                }
            }
        }

        if ($table === 'message_103_records') {
            $oldImagePath = strval(isset($row['local_image_path']) ? $row['local_image_path'] : '');
            if ($oldImagePath !== '') {
                $newImagePath = storage_build_new_path($pdo, 'image', $timestamp, 103, $cameraId, $batchId, $oldImagePath);
                if ($newImagePath !== '') {
                    storage_move_file_if_needed($oldImagePath, $newImagePath, $counter);
                    if ($newImagePath !== $oldImagePath) {
                        $updates['local_image_path'] = $newImagePath;
                        $changed = true;
                    }
                }
            }
        }

        if ($changed) {
            $updates['normalized_json'] = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $setClauses = array();
            $params = array();
            foreach ($updates as $col => $val) {
                $setClauses[] = $col . ' = ?';
                $params[] = $val;
            }
            $params[] = $id;
            $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $counter['updated_rows'] += 1;
        }
    }
}

function storage_migrate_all(PDO $pdo)
{
    storage_bootstrap_settings($pdo);
    $counter = array(
        'updated_rows' => 0,
        'moved_files' => 0,
        'missing_files' => 0,
        'errors' => 0
    );

    $tables = array(
        array('name' => 'message_101_records', 'protocol' => 101, 'columns' => 'id,batch_id,camera_id,event_timestamp_ms,normalized_json'),
        array('name' => 'message_102_records', 'protocol' => 102, 'columns' => 'id,batch_id,camera_id,event_timestamp_ms,embedding_file_path,normalized_json'),
        array('name' => 'message_103_records', 'protocol' => 103, 'columns' => 'id,batch_id,camera_id,event_timestamp_ms,local_image_path,normalized_json')
    );

    foreach ($tables as $table) {
        $tableName = $table['name'];
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $checkStmt->execute(array($tableName));
        if (intval($checkStmt->fetchColumn()) <= 0) {
            continue;
        }
        $stmt = $pdo->query('SELECT ' . $table['columns'] . ' FROM ' . $tableName . ' ORDER BY id ASC');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        storage_rewrite_row_paths($pdo, $tableName, intval($table['protocol']), $rows, $counter);
    }

    return $counter;
}

