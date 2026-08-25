ALTER TABLE catch_email_inboxes
  ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT 'Catch Mail' AFTER user_id;
