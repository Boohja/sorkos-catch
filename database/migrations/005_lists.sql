CREATE TABLE IF NOT EXISTS catch_lists (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  title VARCHAR(160) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_lists_user_title (user_id, title),
  INDEX idx_lists_user_updated (user_id, updated_at),
  CONSTRAINT fk_catch_lists_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_capture_lists (
  capture_id CHAR(36) NOT NULL,
  list_id CHAR(36) NOT NULL,
  assigned_at DATETIME(6) NOT NULL,
  PRIMARY KEY (capture_id, list_id),
  INDEX idx_capture_lists_list_assigned (list_id, assigned_at),
  CONSTRAINT fk_catch_capture_lists_capture FOREIGN KEY (capture_id) REFERENCES catch_captures(id) ON DELETE CASCADE,
  CONSTRAINT fk_catch_capture_lists_list FOREIGN KEY (list_id) REFERENCES catch_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
