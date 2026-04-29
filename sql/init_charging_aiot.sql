-- Charging AIOT database bootstrap
-- Reconstructed from application code.
-- Safe to run multiple times.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS `charging_aiot`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `charging_aiot`;

CREATE TABLE IF NOT EXISTS `user` (
  `user_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user__uuid` CHAR(36) NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `nickname` VARCHAR(64) DEFAULT NULL,
  `role` TINYINT NOT NULL DEFAULT 3,
  `tel` VARCHAR(32) DEFAULT NULL,
  `email` VARCHAR(128) DEFAULT NULL,
  `createtime` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_user_uuid` (`user__uuid`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `storage_path_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_key` VARCHAR(32) NOT NULL,
  `path_template` VARCHAR(255) NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category_key` (`category_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sys_device_group` (
  `group_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_uuid` VARCHAR(40) DEFAULT NULL,
  `group_name` VARCHAR(100) DEFAULT NULL,
  `sort` INT DEFAULT 0,
  `status_flag` TINYINT DEFAULT 1,
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `modify_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`group_id`),
  UNIQUE KEY `uk_group_uuid` (`group_uuid`),
  KEY `idx_group_status` (`status_flag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sys_device` (
  `device_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_uuid` VARCHAR(40) NOT NULL,
  `group_id` BIGINT UNSIGNED DEFAULT NULL,
  `device_name` VARCHAR(100) DEFAULT NULL,
  `brand` VARCHAR(64) DEFAULT NULL,
  `model` VARCHAR(64) DEFAULT NULL,
  `protocol_type` TINYINT DEFAULT NULL,
  `ip_address` VARCHAR(128) DEFAULT NULL,
  `port` INT DEFAULT 554,
  `username` VARCHAR(64) DEFAULT NULL,
  `password` VARCHAR(64) DEFAULT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `online_status` TINYINT DEFAULT 0,
  `status_flag` TINYINT DEFAULT 1,
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `modify_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`device_id`),
  KEY `idx_device_group` (`group_id`),
  KEY `idx_device_status` (`status_flag`),
  KEY `idx_device_online` (`online_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sys_camera_path` (
  `path_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `path_uuid` VARCHAR(40) NOT NULL,
  `device_id` BIGINT UNSIGNED NOT NULL,
  `path_name` VARCHAR(128) NOT NULL,
  `source_url` VARCHAR(255) NOT NULL,
  `stream_type` TINYINT DEFAULT 1,
  `record_enabled` TINYINT DEFAULT 0,
  `record_path` VARCHAR(128) DEFAULT NULL,
  `record_format` VARCHAR(20) DEFAULT 'fmp4',
  `record_part_duration` INT DEFAULT NULL,
  `status_flag` TINYINT DEFAULT 1,
  `modify_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`path_id`),
  UNIQUE KEY `uk_path_uuid` (`path_uuid`),
  UNIQUE KEY `uk_path_name` (`path_name`),
  KEY `idx_camera_path_device` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sys_device_audit_log` (
  `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(32) NOT NULL,
  `action_name` VARCHAR(64) DEFAULT NULL,
  `device_id` BIGINT UNSIGNED DEFAULT NULL,
  `group_id` BIGINT UNSIGNED DEFAULT NULL,
  `path_name` VARCHAR(128) DEFAULT NULL,
  `result_status` TINYINT DEFAULT 1,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `request_payload` LONGTEXT DEFAULT NULL,
  `response_payload` LONGTEXT DEFAULT NULL,
  `client_ip` VARCHAR(64) DEFAULT NULL,
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_audit_event_type` (`event_type`),
  KEY `idx_audit_action_name` (`action_name`),
  KEY `idx_audit_path_name` (`path_name`),
  KEY `idx_audit_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `camera_stream_data` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `msg_type` INT NOT NULL,
  `camera_id` VARCHAR(128) DEFAULT NULL,
  `device_timestamp` BIGINT DEFAULT NULL,
  `payload_data` LONGTEXT,
  `raw_json` LONGTEXT,
  `image_urls` LONGTEXT,
  `source_file_name` VARCHAR(255) DEFAULT NULL,
  `source_file_size` BIGINT DEFAULT 0,
  `status_flag` TINYINT DEFAULT 1,
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `modify_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_type_time` (`msg_type`, `device_timestamp`),
  KEY `idx_camera_time` (`camera_id`, `device_timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `message_101_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `camera_id` VARCHAR(128) NOT NULL,
  `event_timestamp_ms` BIGINT NOT NULL,
  `track_id` BIGINT DEFAULT NULL,
  `obj_type` INT DEFAULT NULL,
  `x1` INT DEFAULT NULL,
  `y1` INT DEFAULT NULL,
  `x2` INT DEFAULT NULL,
  `y2` INT DEFAULT NULL,
  `conf` INT DEFAULT NULL,
  `object_index` INT DEFAULT NULL,
  `protocol_version` INT DEFAULT NULL,
  `frame_header` BIGINT UNSIGNED DEFAULT NULL,
  `frame_tail` BIGINT UNSIGNED DEFAULT NULL,
  `crc_value` BIGINT UNSIGNED DEFAULT NULL,
  `frame_length` BIGINT UNSIGNED DEFAULT NULL,
  `raw_protocol_hex` LONGTEXT,
  `normalized_json` LONGTEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_101_batch_camera_time` (`batch_id`, `camera_id`, `event_timestamp_ms`),
  KEY `idx_101_camera_time` (`camera_id`, `event_timestamp_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `message_102_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `camera_id` VARCHAR(128) NOT NULL,
  `event_timestamp_ms` BIGINT NOT NULL,
  `track_id` BIGINT DEFAULT NULL,
  `obj_type` INT DEFAULT NULL,
  `information` LONGTEXT,
  `person_name` VARCHAR(255) DEFAULT NULL,
  `status_text` VARCHAR(255) DEFAULT NULL,
  `feature_data` LONGTEXT,
  `vector_index` INT DEFAULT NULL,
  `embedding_dim` INT DEFAULT 512,
  `embedding_byte_length` BIGINT DEFAULT 0,
  `embedding_file_path` VARCHAR(255) DEFAULT NULL,
  `embedding_preview` VARCHAR(255) DEFAULT NULL,
  `protocol_version` INT DEFAULT NULL,
  `frame_header` BIGINT UNSIGNED DEFAULT NULL,
  `frame_tail` BIGINT UNSIGNED DEFAULT NULL,
  `crc_value` BIGINT UNSIGNED DEFAULT NULL,
  `frame_length` BIGINT UNSIGNED DEFAULT NULL,
  `raw_protocol_hex` LONGTEXT,
  `normalized_json` LONGTEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_102_batch_camera_time` (`batch_id`, `camera_id`, `event_timestamp_ms`),
  KEY `idx_102_camera_time` (`camera_id`, `event_timestamp_ms`),
  KEY `idx_102_embedding_file_path` (`embedding_file_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `message_103_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `camera_id` VARCHAR(128) NOT NULL,
  `event_timestamp_ms` BIGINT NOT NULL,
  `track_id` BIGINT DEFAULT NULL,
  `obj_type` INT DEFAULT NULL,
  `person_count` INT DEFAULT 0,
  `car_count` INT DEFAULT 0,
  `frame_image_url` VARCHAR(255) DEFAULT NULL,
  `image_fetch_status` VARCHAR(64) DEFAULT NULL,
  `local_image_path` VARCHAR(255) DEFAULT NULL,
  `image_index` INT DEFAULT NULL,
  `image_byte_length` BIGINT DEFAULT 0,
  `protocol_version` INT DEFAULT NULL,
  `frame_header` BIGINT UNSIGNED DEFAULT NULL,
  `frame_tail` BIGINT UNSIGNED DEFAULT NULL,
  `crc_value` BIGINT UNSIGNED DEFAULT NULL,
  `frame_length` BIGINT UNSIGNED DEFAULT NULL,
  `raw_protocol_hex` LONGTEXT,
  `image_downloaded_at` DATETIME DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `normalized_json` LONGTEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_103_batch_camera_time` (`batch_id`, `camera_id`, `event_timestamp_ms`),
  KEY `idx_103_camera_time` (`camera_id`, `event_timestamp_ms`),
  KEY `idx_103_local_image_path` (`local_image_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `storage_path_settings` (`category_key`, `path_template`) VALUES
  ('raw_upload', 'storage/{date}/{protocol}/{camera}/raw_upload'),
  ('frame', 'storage/{date}/{protocol}/{camera}/frame'),
  ('payload', 'storage/{date}/{protocol}/{camera}/payload'),
  ('image', 'storage/{date}/{protocol}/{camera}/image'),
  ('embedding', 'storage/{date}/{protocol}/{camera}/embedding/batch_{batch}')
ON DUPLICATE KEY UPDATE `path_template` = VALUES(`path_template`);

INSERT INTO `sys_device_group` (`group_uuid`, `group_name`, `sort`, `status_flag`) VALUES
  ('default-group-1', 'Default Group', 1, 1),
  ('default-group-2', 'Focus Area', 2, 1),
  ('default-group-3', 'Entrance Exit', 3, 1)
ON DUPLICATE KEY UPDATE
  `group_name` = VALUES(`group_name`),
  `sort` = VALUES(`sort`),
  `status_flag` = VALUES(`status_flag`);

INSERT INTO `user` (`user__uuid`, `username`, `password`, `nickname`, `role`, `tel`, `email`) VALUES
  ('11111111-1111-4111-8111-111111111111', 'admin', '$2y$10$fEpJDQg/Zzk9EFLthOi2ouX0GROpsWjjjDdnNYu4plifQ65pzriLa', '系统管理员', 1, '', '')
ON DUPLICATE KEY UPDATE
  `password` = VALUES(`password`),
  `nickname` = VALUES(`nickname`),
  `role` = VALUES(`role`),
  `tel` = VALUES(`tel`),
  `email` = VALUES(`email`);
