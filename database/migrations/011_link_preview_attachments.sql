ALTER TABLE catch_attachments
  ADD COLUMN kind ENUM('source','preview') NOT NULL DEFAULT 'source' AFTER capture_id,
  ADD INDEX idx_attachments_capture_kind (capture_id, kind);
