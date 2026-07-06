ALTER TABLE purchase_item_catalog
  ADD COLUMN tier_prices JSON DEFAULT NULL AFTER price_tier3;

-- Migrate existing tier data to JSON format
UPDATE purchase_item_catalog
  SET tier_prices = JSON_ARRAY(
    JSON_OBJECT('label', 'บิล1', 'price', COALESCE(price_tier1, 0)),
    JSON_OBJECT('label', 'บิล2', 'price', COALESCE(price_tier2, 0)),
    JSON_OBJECT('label', 'บิล3', 'price', COALESCE(price_tier3, 0))
  );
