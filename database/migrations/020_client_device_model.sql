RENAME TABLE
  catch_devices TO catch_clients,
  catch_device_tokens TO catch_client_tokens,
  catch_device_pairing_codes TO catch_client_pairing_codes;

CREATE TABLE catch_devices (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  device_type ENUM('laptop','phone','pc','tablet') NOT NULL DEFAULT 'pc',
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_catch_devices_user_name (user_id, name),
  INDEX idx_catch_devices_user_created (user_id, created_at),
  CONSTRAINT fk_physical_devices_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE catch_clients
  ADD COLUMN device_id CHAR(36) NULL AFTER user_id,
  ADD INDEX idx_catch_clients_device (device_id),
  ADD CONSTRAINT fk_catch_clients_device FOREIGN KEY (device_id) REFERENCES catch_devices(id) ON DELETE SET NULL;

ALTER TABLE catch_client_tokens
  CHANGE COLUMN device_id client_id CHAR(36) NOT NULL;

ALTER TABLE catch_client_pairing_codes
  CHANGE COLUMN device_id client_id CHAR(36) NOT NULL;

ALTER TABLE catch_extension_pairing_requests
  CHANGE COLUMN device_id client_id CHAR(36) NULL;

ALTER TABLE catch_cli_auth_requests
  CHANGE COLUMN device_id client_id CHAR(36) NULL;

ALTER TABLE catch_capture_debug_requests
  CHANGE COLUMN device_id client_id CHAR(36) NOT NULL;

ALTER TABLE catch_captures
  CHANGE COLUMN device_id client_id CHAR(36) NULL;
