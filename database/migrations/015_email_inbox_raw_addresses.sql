ALTER TABLE catch_email_inboxes
  ADD COLUMN address VARCHAR(254) NULL AFTER user_id,
  DROP INDEX token_hash,
  DROP COLUMN token_hash;

ALTER TABLE catch_email_inboxes
  MODIFY COLUMN address VARCHAR(254) NOT NULL,
  ADD CONSTRAINT uq_email_inboxes_address UNIQUE (address);
