CREATE TABLE IF NOT EXISTS catch_users (
  id CHAR(36) PRIMARY KEY,
  sorkos_user_id VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(254) NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  display_name VARCHAR(120) NOT NULL,
  avatar_url TEXT NULL,
  preferred_language VARCHAR(8) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_devices (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind ENUM('mobile','desktop') NOT NULL,
  platform VARCHAR(32) NOT NULL,
  status ENUM('setup','connected') NOT NULL DEFAULT 'setup',
  created_at DATETIME(6) NOT NULL,
  connected_at DATETIME(6) NULL,
  last_seen_at DATETIME(6) NULL,
  INDEX idx_catch_devices_user (user_id, created_at),
  CONSTRAINT fk_catch_devices_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_device_pairing_codes (
  device_id CHAR(36) PRIMARY KEY,
  code_hash CHAR(64) NOT NULL UNIQUE,
  code_encrypted TEXT NOT NULL,
  created_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_catch_device_pairing_device FOREIGN KEY (device_id) REFERENCES catch_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_device_tokens (
  id CHAR(36) PRIMARY KEY,
  device_id CHAR(36) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL UNIQUE,
  token_scope VARCHAR(40) NOT NULL DEFAULT 'capture:write',
  last_used_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_catch_device_token_device FOREIGN KEY (device_id) REFERENCES catch_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_captures (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  client_capture_id VARCHAR(128) NOT NULL,
  type ENUM('text','url','image','audio','file','mixed') NOT NULL,
  title VARCHAR(500) NULL,
  text MEDIUMTEXT NULL,
  url TEXT NULL,
  extracted_text MEDIUMTEXT NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'web',
  metadata_json JSON NULL,
  status ENUM('inbox','archived','deleted') NOT NULL DEFAULT 'inbox',
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  archived_at DATETIME(6) NULL,
  deleted_at DATETIME(6) NULL,
  UNIQUE KEY uq_captures_user_client (user_id, client_capture_id),
  INDEX idx_captures_user_status_created (user_id, status, created_at),
  CONSTRAINT fk_catch_captures_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_attachments (
  id CHAR(36) PRIMARY KEY,
  capture_id CHAR(36) NOT NULL,
  original_name VARCHAR(500) NOT NULL,
  storage_name VARCHAR(255) NOT NULL UNIQUE,
  mime_type VARCHAR(160) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  checksum CHAR(64) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  INDEX idx_attachments_capture (capture_id),
  CONSTRAINT fk_catch_attachments_capture FOREIGN KEY (capture_id) REFERENCES catch_captures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_tags (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  name VARCHAR(100) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_tags_user_name (user_id, name),
  CONSTRAINT fk_catch_tags_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_capture_tags (
  capture_id CHAR(36) NOT NULL,
  tag_id CHAR(36) NOT NULL,
  PRIMARY KEY (capture_id, tag_id),
  CONSTRAINT fk_catch_capture_tags_capture FOREIGN KEY (capture_id) REFERENCES catch_captures(id) ON DELETE CASCADE,
  CONSTRAINT fk_catch_capture_tags_tag FOREIGN KEY (tag_id) REFERENCES catch_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_webhook_endpoints (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, name VARCHAR(120) NOT NULL,
  url TEXT NOT NULL, http_method VARCHAR(10) NOT NULL DEFAULT 'POST', headers_encrypted TEXT NULL,
  secret_encrypted TEXT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
  INDEX idx_webhook_endpoints_user (user_id),
  CONSTRAINT fk_catch_webhook_endpoints_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_rules (
  id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, name VARCHAR(120) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1, priority INT NOT NULL DEFAULT 100,
  conditions_json JSON NOT NULL, actions_json JSON NOT NULL, stop_processing TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
  INDEX idx_rules_user_priority (user_id, enabled, priority),
  CONSTRAINT fk_catch_rules_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_webhook_deliveries (
  id CHAR(36) PRIMARY KEY, webhook_endpoint_id CHAR(36) NOT NULL, capture_id CHAR(36) NOT NULL,
  event_name VARCHAR(100) NOT NULL, request_payload JSON NOT NULL, response_status SMALLINT NULL,
  response_body_excerpt TEXT NULL, attempt_count INT NOT NULL DEFAULT 0, next_attempt_at DATETIME(6) NULL,
  delivered_at DATETIME(6) NULL, failed_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL,
  INDEX idx_deliveries_capture (capture_id), INDEX idx_deliveries_retry (next_attempt_at, delivered_at),
  CONSTRAINT fk_catch_deliveries_endpoint FOREIGN KEY (webhook_endpoint_id) REFERENCES catch_webhook_endpoints(id) ON DELETE CASCADE,
  CONSTRAINT fk_catch_deliveries_capture FOREIGN KEY (capture_id) REFERENCES catch_captures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_jobs (
  id CHAR(36) PRIMARY KEY, type VARCHAR(100) NOT NULL, payload_json JSON NOT NULL,
  status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0,
  available_at DATETIME(6) NOT NULL, locked_at DATETIME(6) NULL, finished_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL,
  INDEX idx_jobs_available (status, available_at, locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_migrations (
  migration VARCHAR(255) PRIMARY KEY,
  applied_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
