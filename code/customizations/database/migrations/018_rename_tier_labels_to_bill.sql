-- Migration: 017_rename_tier_labels_to_bill.sql
-- เปลี่ยนชื่อ label จาก เทียร → บิล ตามที่ผู้ว่าจ้างต้องการ
-- บิล1 = เทียร1, บิล2 = เทียร2, บิล3 = เทียร3

UPDATE purchase_item_catalog
SET tier_prices = JSON_SET(
  tier_prices,
  '$[0].label', REPLACE(JSON_UNQUOTE(JSON_EXTRACT(tier_prices, '$[0].label')), 'เทียร', 'บิล'),
  '$[1].label', REPLACE(JSON_UNQUOTE(JSON_EXTRACT(tier_prices, '$[1].label')), 'เทียร', 'บิล'),
  '$[2].label', REPLACE(JSON_UNQUOTE(JSON_EXTRACT(tier_prices, '$[2].label')), 'เทียร', 'บิล')
)
WHERE JSON_UNQUOTE(JSON_EXTRACT(tier_prices, '$[0].label')) LIKE '%เทียร%'
   OR JSON_UNQUOTE(JSON_EXTRACT(tier_prices, '$[1].label')) LIKE '%เทียร%'
   OR JSON_UNQUOTE(JSON_EXTRACT(tier_prices, '$[2].label')) LIKE '%เทียร%';
