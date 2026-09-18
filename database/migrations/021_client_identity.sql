ALTER TABLE catch_clients
  ADD COLUMN os VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER platform,
  ADD COLUMN client_icon VARCHAR(32) NOT NULL DEFAULT 'app' AFTER os;

UPDATE catch_clients
SET os = CASE
  WHEN LOWER(COALESCE(user_agent, '')) LIKE '%windows%' OR platform = 'windows' THEN 'windows'
  WHEN LOWER(COALESCE(user_agent, '')) LIKE '%android%' OR platform = 'android' THEN 'android'
  WHEN LOWER(COALESCE(user_agent, '')) LIKE '%ipad%' OR platform = 'ipados' THEN 'ipados'
  WHEN LOWER(COALESCE(user_agent, '')) LIKE '%iphone%' OR platform = 'ios' THEN 'ios'
  WHEN LOWER(COALESCE(user_agent, '')) LIKE '%mac os x%' OR platform = 'macos' THEN 'macos'
  WHEN LOWER(COALESCE(user_agent, '')) LIKE '%linux%' OR platform = 'linux' THEN 'linux'
  ELSE 'unknown'
END,
client_icon = CASE
  WHEN client_type = 'cli' THEN 'cli'
  WHEN platform = 'firefox' OR LOWER(COALESCE(user_agent, '')) LIKE '%firefox/%' THEN 'brand-firefox'
  WHEN platform IN ('chrome', 'chromium') OR LOWER(COALESCE(user_agent, '')) LIKE '%chrome/%' THEN 'brand-chrome'
  WHEN client_type = 'extension' THEN 'extension'
  ELSE 'app'
END;
