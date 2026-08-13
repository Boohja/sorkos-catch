ALTER TABLE catch_devices
  MODIFY COLUMN device_type ENUM('laptop','phone','pc','tablet','extension','cli')
  NOT NULL DEFAULT 'pc';

UPDATE catch_devices
SET device_type = CASE
  WHEN client_type = 'extension' THEN 'extension'
  WHEN client_type = 'cli' THEN 'cli'
  ELSE device_type
END
WHERE client_type IN ('extension', 'cli');
