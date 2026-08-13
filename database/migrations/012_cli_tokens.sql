ALTER TABLE catch_devices
  MODIFY COLUMN client_type ENUM('web','extension','shortcut','api','cli') NOT NULL DEFAULT 'shortcut';

ALTER TABLE catch_device_tokens
  ADD COLUMN expires_at DATETIME(6) NULL AFTER created_at,
  ADD COLUMN revoked_at DATETIME(6) NULL AFTER expires_at;

CREATE TABLE IF NOT EXISTS catch_cli_auth_requests (
  login_id CHAR(48) PRIMARY KEY,
  code_challenge CHAR(43) NOT NULL,
  device_name VARCHAR(120) NOT NULL,
  platform VARCHAR(32) NOT NULL,
  status ENUM('pending','approved') NOT NULL DEFAULT 'pending',
  user_id CHAR(36) NULL,
  device_id CHAR(36) NULL,
  expires_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  approved_at DATETIME(6) NULL,
  INDEX idx_cli_auth_expiry (expires_at),
  CONSTRAINT fk_cli_auth_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cli_auth_device FOREIGN KEY (device_id) REFERENCES catch_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
