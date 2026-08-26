-- Migration 073: use catalog_id as the durable stock identity.
-- Names remain for display/reporting. Legacy rows are backfilled by exact name,
-- while ambiguous/unmatched names stay NULL and require manual review.

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branch_stock' AND COLUMN_NAME = 'catalog_id'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE branch_stock ADD COLUMN catalog_id INT NULL AFTER category_id',
  'SELECT ''073 branch_stock.catalog_id already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order_items' AND COLUMN_NAME = 'catalog_id'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE purchase_order_items ADD COLUMN catalog_id INT NULL AFTER item_name',
  'SELECT ''073 purchase_order_items.catalog_id already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transfer_items' AND COLUMN_NAME = 'catalog_id'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE stock_transfer_items ADD COLUMN catalog_id INT NULL AFTER category_id',
  'SELECT ''073 stock_transfer_items.catalog_id already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE branch_stock bs
JOIN categories c ON c.id = bs.category_id
JOIN purchase_item_catalog pic
  ON pic.category_id = c.id
 AND CONVERT(TRIM(CONVERT(pic.name USING utf8mb4)) USING utf8mb4) =
     CONVERT(TRIM(bs.item_name) USING utf8mb4)
SET bs.catalog_id = pic.id
WHERE bs.catalog_id IS NULL;

UPDATE purchase_order_items poi
JOIN categories c ON c.id = poi.category_id
JOIN purchase_item_catalog pic
  ON pic.category_id = c.id
 AND CONVERT(TRIM(CONVERT(pic.name USING utf8mb4)) USING utf8mb4) =
     CONVERT(TRIM(poi.item_name) USING utf8mb4)
SET poi.catalog_id = pic.id
WHERE poi.catalog_id IS NULL;

UPDATE stock_transfer_items sti
JOIN categories c ON c.id = sti.category_id
JOIN purchase_item_catalog pic
  ON pic.category_id = c.id
 AND CONVERT(TRIM(CONVERT(pic.name USING utf8mb4)) USING utf8mb4) =
     CONVERT(TRIM(sti.item_name) USING utf8mb4)
SET sti.catalog_id = pic.id
WHERE sti.catalog_id IS NULL;

UPDATE sale_lot_items sli
JOIN categories c ON c.id = sli.category_id
JOIN purchase_item_catalog pic
  ON pic.category_id = c.id
 AND CONVERT(TRIM(CONVERT(pic.name USING utf8mb4)) USING utf8mb4) =
     CONVERT(TRIM(sli.item_name) USING utf8mb4)
SET sli.catalog_id = pic.id
WHERE sli.catalog_id IS NULL;

UPDATE branch_stock bs
JOIN purchase_item_catalog pic
  ON pic.category_id = bs.category_id
 AND CONVERT(TRIM(pic.name) USING utf8mb4) =
     _utf8mb4 0xE0B980E0B884E0B8A3E0B8B7E0B988E0B8ADE0B887E0B884E0B8ADE0B8A1E0B89EE0B8B4E0B8A7E0B980E0B895E0B8ADE0B8A3E0B98C
SET bs.catalog_id = pic.id
WHERE bs.catalog_id IS NULL
  AND CONVERT(TRIM(bs.item_name) USING utf8mb4) =
      _utf8mb4 0xE0B980E0B884E0B8A3E0B988E0B8ADE0B884E0B8ADE0B8A1E0B89EE0B8B4E0B8A7E0B980E0B895E0B8ADE0B8A3E0B98C;

UPDATE purchase_order_items poi
JOIN purchase_item_catalog pic
  ON pic.category_id = poi.category_id
 AND CONVERT(TRIM(pic.name) USING utf8mb4) =
     _utf8mb4 0xE0B980E0B884E0B8A3E0B8B7E0B988E0B8ADE0B887E0B884E0B8ADE0B8A1E0B89EE0B8B4E0B8A7E0B980E0B895E0B8ADE0B8A3E0B98C
SET poi.catalog_id = pic.id
WHERE poi.catalog_id IS NULL
  AND CONVERT(TRIM(poi.item_name) USING utf8mb4) =
      _utf8mb4 0xE0B980E0B884E0B8A3E0B988E0B8ADE0B884E0B8ADE0B8A1E0B89EE0B8B4E0B8A7E0B980E0B895E0B8ADE0B8A3E0B98C;

CREATE TABLE IF NOT EXISTS catalog_item_aliases (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    catalog_id   INT NOT NULL,
    alias_name   VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    created_by   INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_catalog_alias (alias_name, catalog_id),
    KEY idx_catalog_alias_catalog (catalog_id),
    CONSTRAINT fk_catalog_alias_catalog FOREIGN KEY (catalog_id)
      REFERENCES purchase_item_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy rows can use the same product under different spellings. Move their
-- quantity into the canonical catalog row and remove empty duplicates before
-- adding catalog indexes/FKs.
UPDATE branch_stock source
JOIN catalog_item_aliases alias
  ON CONVERT(TRIM(alias.alias_name) USING utf8mb4) =
     CONVERT(TRIM(source.item_name) USING utf8mb4)
JOIN purchase_item_catalog source_pic ON source_pic.id = alias.catalog_id
 AND source_pic.category_id = source.category_id
SET source.catalog_id = alias.catalog_id;

UPDATE branch_stock source
JOIN purchase_item_catalog source_pic ON source_pic.id = source.catalog_id
JOIN catalog_item_aliases alias
  ON CONVERT(TRIM(alias.alias_name) USING utf8mb4) =
     CONVERT(TRIM(source.item_name) USING utf8mb4)
 AND alias.catalog_id = source.catalog_id
JOIN branch_stock target
  ON target.branch_id = source.branch_id
 AND target.category_id = source.category_id
 AND target.catalog_id = source.catalog_id
 AND target.id <> source.id
SET source.stock_kg = source.stock_kg,
    source.item_name = IF(
      target.stock_kg > 0,
      CONCAT('__merge__', source.id),
      CONCAT('__empty__', source.id)
    ),
    target.stock_kg = target.stock_kg + source.stock_kg;

UPDATE branch_stock keeper
JOIN purchase_item_catalog pic ON pic.id = keeper.catalog_id
SET keeper.item_name = IF(
  keeper.stock_kg > 0,
  CONCAT('__canonical__', keeper.id),
  CONCAT('__empty__', keeper.id)
)
WHERE keeper.catalog_id IS NOT NULL;

DELETE remove_row FROM branch_stock remove_row
LEFT JOIN branch_stock keeper
  ON keeper.branch_id = remove_row.branch_id
 AND keeper.category_id = remove_row.category_id
 AND keeper.catalog_id = remove_row.catalog_id
 AND keeper.item_name LIKE '__canonical__%'
WHERE remove_row.catalog_id IS NOT NULL
  AND remove_row.item_name LIKE '__empty__%';

DELETE remove_row FROM branch_stock remove_row
JOIN branch_stock keeper
  ON keeper.branch_id = remove_row.branch_id
 AND keeper.category_id = remove_row.category_id
 AND keeper.catalog_id = remove_row.catalog_id
 AND keeper.id <> remove_row.id
WHERE remove_row.catalog_id IS NOT NULL
  AND remove_row.stock_kg = 0
  AND remove_row.item_name LIKE '__empty__%'
  AND keeper.item_name LIKE '__canonical__%';

DELETE remove_row FROM branch_stock remove_row
JOIN branch_stock keeper
  ON keeper.branch_id = remove_row.branch_id
 AND keeper.category_id = remove_row.category_id
 AND keeper.catalog_id = remove_row.catalog_id
 AND keeper.id <> remove_row.id
WHERE remove_row.catalog_id IS NOT NULL
  AND remove_row.stock_kg = 0
  AND LEFT(remove_row.item_name, 8) = '__empty__'
  AND LEFT(keeper.item_name, 13) = '__canonical__';

UPDATE branch_stock keeper
JOIN purchase_item_catalog pic ON pic.id = keeper.catalog_id
SET keeper.item_name = pic.name
WHERE keeper.catalog_id IS NOT NULL
  AND LEFT(keeper.item_name, 13) = '__canonical__';

UPDATE branch_stock canonical
JOIN purchase_item_catalog pic ON pic.id = canonical.catalog_id
SET canonical.item_name = pic.name;

INSERT IGNORE INTO catalog_item_aliases (catalog_id, alias_name, created_by)
SELECT pic.id, CONVERT(TRIM(bs.item_name) USING utf8mb4), NULL
FROM branch_stock bs
JOIN purchase_item_catalog pic ON pic.id = bs.catalog_id
WHERE CONVERT(TRIM(bs.item_name) USING utf8mb4) <>
      CONVERT(TRIM(pic.name) USING utf8mb4);

INSERT IGNORE INTO catalog_item_aliases (catalog_id, alias_name, created_by)
SELECT pic.id, CONVERT(TRIM(poi.item_name) USING utf8mb4), NULL
FROM purchase_order_items poi
JOIN purchase_item_catalog pic ON pic.id = poi.catalog_id
WHERE CONVERT(TRIM(poi.item_name) USING utf8mb4) <>
      CONVERT(TRIM(pic.name) USING utf8mb4);

INSERT IGNORE INTO catalog_item_aliases (catalog_id, alias_name, created_by)
SELECT pic.id, CONVERT(TRIM(sti.item_name) USING utf8mb4), NULL
FROM stock_transfer_items sti
JOIN purchase_item_catalog pic ON pic.id = sti.catalog_id
WHERE sti.item_name IS NOT NULL
  AND CONVERT(TRIM(sti.item_name) USING utf8mb4) <>
      CONVERT(TRIM(pic.name) USING utf8mb4);

INSERT IGNORE INTO catalog_item_aliases (catalog_id, alias_name, created_by)
SELECT pic.id, CONVERT(TRIM(sli.item_name) USING utf8mb4), NULL
FROM sale_lot_items sli
JOIN purchase_item_catalog pic ON pic.id = sli.catalog_id
WHERE sli.item_name IS NOT NULL
  AND CONVERT(TRIM(sli.item_name) USING utf8mb4) <>
      CONVERT(TRIM(pic.name) USING utf8mb4);
