ALTER TABLE purchase_item_catalog
  ADD COLUMN price_tier1 DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER default_price,
  ADD COLUMN price_tier2 DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER price_tier1,
  ADD COLUMN price_tier3 DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER price_tier2;
