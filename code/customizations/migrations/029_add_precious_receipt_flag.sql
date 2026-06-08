ALTER TABLE categories ADD COLUMN requires_precious_receipt TINYINT(1) NOT NULL DEFAULT 0;
UPDATE categories SET requires_precious_receipt = 1 WHERE name LIKE '%ทองแดง%' OR name = 'โลหะมีค่า';
