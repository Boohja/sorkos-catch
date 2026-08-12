UPDATE catch_captures c
SET c.status='archived',
    c.archived_at=COALESCE(c.archived_at,UTC_TIMESTAMP(6)),
    c.updated_at=UTC_TIMESTAMP(6)
WHERE c.deleted_at IS NULL
  AND EXISTS (SELECT 1 FROM catch_capture_lists cl WHERE cl.capture_id=c.id)
  AND c.status<>'archived';
