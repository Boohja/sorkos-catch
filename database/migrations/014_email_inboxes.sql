CREATE TABLE IF NOT EXISTS catch_email_inboxes (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  INDEX idx_email_inboxes_user (user_id, revoked_at, created_at),
  CONSTRAINT fk_email_inboxes_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_email_imports (
  id CHAR(36) PRIMARY KEY,
  inbox_id CHAR(36) NOT NULL,
  message_key_hash CHAR(64) NOT NULL,
  message_id VARCHAR(998) NULL,
  capture_id CHAR(36) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_email_imports_inbox_message (inbox_id, message_key_hash),
  INDEX idx_email_imports_capture (capture_id),
  CONSTRAINT fk_email_imports_inbox FOREIGN KEY (inbox_id) REFERENCES catch_email_inboxes(id) ON DELETE CASCADE,
  CONSTRAINT fk_email_imports_capture FOREIGN KEY (capture_id) REFERENCES catch_captures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
