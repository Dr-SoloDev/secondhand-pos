-- 049: Set default prices on catalog items and fix NULL category_id
-- Without this, the price display shows "—" when selecting items

-- Step 1: Fix NULL category_id on catalog items
UPDATE purchase_item_catalog
SET category_id = (SELECT id FROM categories WHERE name = 'โลหะ' LIMIT 1)
WHERE code IN ('M01','M02','M03','M04','M05','M06') AND category_id IS NULL;

UPDATE purchase_item_catalog
SET category_id = (SELECT id FROM categories WHERE name = 'กระดาษ' LIMIT 1)
WHERE code IN ('P01','P02','P03','P04','P05') AND category_id IS NULL;

-- Step 2: Set category tier prices (ราคารับซื้อ 3 ระดับต่อหมวด)
UPDATE categories SET price_tier1 = 8.00,  price_tier2 = 7.00,  price_tier3 = 6.00  WHERE name = 'โลหะ';
UPDATE categories SET price_tier1 = 310.00, price_tier2 = 300.00, price_tier3 = 290.00 WHERE name = 'โลหะมีค่า';
UPDATE categories SET price_tier1 = 15.00, price_tier2 = 13.00,   price_tier3 = 11.00  WHERE name = 'เครื่องใช้ไฟฟ้า';
UPDATE categories SET price_tier1 = 1.00,  price_tier2 = 0.80,    price_tier3 = 0.50   WHERE name = 'พลาสติก';
UPDATE categories SET price_tier1 = 5.00,  price_tier2 = 4.00,    price_tier3 = 3.00   WHERE name = 'เฟอร์นิเจอร์';
UPDATE categories SET price_tier1 = 6.00,  price_tier2 = 5.50,    price_tier3 = 5.00   WHERE name = 'กระดาษ';
UPDATE categories SET price_tier1 = 50.00, price_tier2 = 40.00,   price_tier3 = 30.00  WHERE name = 'มือถือและอุปกรณ์';
UPDATE categories SET price_tier1 = 10.00, price_tier2 = 8.00,    price_tier3 = 6.00   WHERE name = 'แบตเตอรี่';

-- Step 3: Set default_price + tier_prices JSON on catalog items
UPDATE purchase_item_catalog
SET default_price = 8.00,
    tier_prices = '[{"label":"บิล1","price":8.00},{"label":"บิล2","price":7.00},{"label":"บิล3","price":6.00}]'
WHERE code IN ('M01','M02','M03','M04','M05','M06');

UPDATE purchase_item_catalog
SET default_price = 6.00,
    tier_prices = '[{"label":"บิล1","price":6.00},{"label":"บิล2","price":5.50},{"label":"บิล3","price":5.00}]'
WHERE code IN ('P01','P02','P03','P04','P05');
