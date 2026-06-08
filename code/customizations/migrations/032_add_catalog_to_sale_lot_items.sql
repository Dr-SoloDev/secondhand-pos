ALTER TABLE sale_lot_items
  ADD COLUMN catalog_id INT DEFAULT NULL AFTER sale_lot_id,
  ADD COLUMN item_name VARCHAR(255) DEFAULT NULL AFTER catalog_id,
  MODIFY COLUMN category_id INT DEFAULT NULL;
