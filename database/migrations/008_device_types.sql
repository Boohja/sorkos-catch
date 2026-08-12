ALTER TABLE catch_devices
  ADD COLUMN device_type ENUM('laptop','phone','pc','tablet') NULL AFTER kind;

UPDATE catch_devices
SET device_type=CASE
  WHEN platform='ipados' THEN 'tablet'
  WHEN kind='mobile' OR platform IN ('ios','android') THEN 'phone'
  ELSE 'pc'
END;

ALTER TABLE catch_devices
  MODIFY COLUMN device_type ENUM('laptop','phone','pc','tablet') NOT NULL DEFAULT 'pc';
