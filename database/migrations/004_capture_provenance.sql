ALTER TABLE catch_devices
  MODIFY status ENUM('setup','connected','revoked') NOT NULL DEFAULT 'setup',
  ADD COLUMN client_type ENUM('web','extension','shortcut','api') NOT NULL DEFAULT 'shortcut' AFTER kind,
  ADD COLUMN user_agent VARCHAR(500) NULL AFTER platform;

UPDATE catch_devices
SET client_type = 'extension'
WHERE platform IN ('chrome', 'chromium', 'firefox');

ALTER TABLE catch_extension_pairing_requests
  ADD COLUMN user_agent VARCHAR(500) NULL AFTER platform;

ALTER TABLE catch_captures
  ADD COLUMN device_id CHAR(36) NULL AFTER user_id,
  ADD INDEX idx_captures_device (device_id),
  ADD CONSTRAINT fk_captures_device FOREIGN KEY (device_id) REFERENCES catch_devices(id) ON DELETE SET NULL;
