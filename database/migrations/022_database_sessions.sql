CREATE TABLE catch_sessions (
  id VARCHAR(128) PRIMARY KEY,
  user_id CHAR(36) NULL,
  client_id CHAR(36) NULL,
  payload MEDIUMBLOB NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  INDEX idx_catch_sessions_user_expiry (user_id, expires_at),
  INDEX idx_catch_sessions_client_expiry (client_id, expires_at),
  INDEX idx_catch_sessions_expiry (expires_at),
  CONSTRAINT fk_catch_sessions_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_catch_sessions_client FOREIGN KEY (client_id) REFERENCES catch_clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE catch_clients
SET client_icon = CASE
  WHEN client_type = 'extension' AND client_icon = 'brand-firefox' THEN 'firefox-addon'
  WHEN client_type = 'extension' AND client_icon = 'brand-chrome' THEN 'chrome-extension'
  WHEN client_type = 'extension' THEN 'browser-extension'
  WHEN client_type = 'shortcut' THEN 'shortcut'
  WHEN client_type = 'cli' THEN 'cli'
  WHEN client_type = 'api' THEN 'api'
  WHEN client_icon = 'brand-firefox' THEN 'firefox'
  WHEN client_icon = 'brand-chrome' THEN 'chrome'
  WHEN LOWER(COALESCE(user_agent, '')) LIKE '%edg/%' THEN 'edge'
  WHEN LOWER(COALESCE(user_agent, '')) LIKE '%version/%safari/%' THEN 'safari'
  ELSE 'web-app'
END;
