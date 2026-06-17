ALTER TABLE sellers
  ADD COLUMN blacklist_reason VARCHAR(255) DEFAULT NULL AFTER is_blacklisted,
  ADD COLUMN blacklisted_at   DATETIME    DEFAULT NULL AFTER blacklist_reason;
