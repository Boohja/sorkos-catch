CREATE TABLE IF NOT EXISTS catch_targets (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  type VARCHAR(40) NOT NULL,
  config_json JSON NOT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  INDEX idx_catch_targets_user (user_id, created_at),
  CONSTRAINT fk_catch_targets_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_actions (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  INDEX idx_catch_actions_user (user_id, created_at),
  CONSTRAINT fk_catch_actions_user FOREIGN KEY (user_id) REFERENCES catch_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catch_action_steps (
  id CHAR(36) PRIMARY KEY,
  action_id CHAR(36) NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  config_json JSON NOT NULL,
  UNIQUE KEY uq_catch_action_steps_position (action_id, position),
  INDEX idx_catch_action_steps_action (action_id, position),
  CONSTRAINT fk_catch_action_steps_action FOREIGN KEY (action_id) REFERENCES catch_actions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
