UPDATE catch_devices
SET device_type=CASE
  WHEN LOWER(CONCAT_WS(' ',platform,user_agent)) LIKE '%ipad%'
    OR LOWER(CONCAT_WS(' ',platform,user_agent)) LIKE '%tablet%' THEN 'tablet'
  WHEN kind='mobile'
    OR LOWER(CONCAT_WS(' ',platform,user_agent)) LIKE '%iphone%'
    OR LOWER(CONCAT_WS(' ',platform,user_agent)) LIKE '%android%' THEN 'phone'
  ELSE device_type
END;
