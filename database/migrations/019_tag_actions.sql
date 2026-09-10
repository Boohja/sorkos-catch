ALTER TABLE catch_tags
  ADD COLUMN action_id CHAR(36) NULL AFTER name,
  ADD INDEX idx_catch_tags_action (action_id),
  ADD CONSTRAINT fk_catch_tags_action
    FOREIGN KEY (action_id) REFERENCES catch_actions(id) ON DELETE SET NULL;
