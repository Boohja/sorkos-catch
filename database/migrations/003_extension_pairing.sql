CREATE TABLE IF NOT EXISTS catch_extension_pairing_requests (
  request_id CHAR(48) PRIMARY KEY,
  code_challenge CHAR(43) NOT NULL,
  device_name VARCHAR(120) NOT NULL,
  platform VARCHAR(32) NOT NULL,
  status ENUM('pending','approved') NOT NULL DEFAULT 'pending',
  user_id CHAR(36) NULL,
  device_id CHAR(36) NULL,
  token_encrypted TEXT NULL,
  expires_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  approved_at DATETIME(6) NULL,
  INDEX idx_extension_pairing_expiry (expires_at),
  CONSTRAINT fk_extension_pairing_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_extension_pairing_device FOREIGN KEY (device_id) REFERENCES catch_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
