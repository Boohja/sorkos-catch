ALTER TABLE catch_captures
  MODIFY status ENUM('inbox','later','archived') NOT NULL DEFAULT 'inbox',
  ADD COLUMN later_until DATETIME(6) NULL AFTER archived_at;

CREATE INDEX idx_captures_user_later_until
  ON catch_captures (user_id, status, later_until);
