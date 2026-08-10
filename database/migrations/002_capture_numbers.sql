ALTER TABLE catch_users
  ADD COLUMN next_catch_number BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER preferred_language;

ALTER TABLE catch_captures
  ADD COLUMN catch_number BIGINT UNSIGNED NULL AFTER user_id;

UPDATE catch_captures AS captures
JOIN (
  SELECT
    id,
    ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY created_at, id) AS catch_number
  FROM catch_captures
) AS numbered ON numbered.id = captures.id
SET captures.catch_number = numbered.catch_number;

UPDATE catch_users AS users
LEFT JOIN (
  SELECT user_id, MAX(catch_number) + 1 AS next_catch_number
  FROM catch_captures
  GROUP BY user_id
) AS numbered ON numbered.user_id = users.id
SET users.next_catch_number = COALESCE(numbered.next_catch_number, 1);

ALTER TABLE catch_captures
  MODIFY COLUMN catch_number BIGINT UNSIGNED NOT NULL,
  ADD UNIQUE KEY uq_captures_user_number (user_id, catch_number);
