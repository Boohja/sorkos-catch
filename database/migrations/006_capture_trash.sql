UPDATE catch_captures SET status='archived' WHERE status='deleted';

ALTER TABLE catch_captures
  MODIFY status ENUM('inbox','archived') NOT NULL DEFAULT 'inbox';

CREATE INDEX idx_captures_user_deleted ON catch_captures (user_id, deleted_at);
