-- Migration: Add price tiers to categories
-- 3-level pricing per category for secondhand purchasing
-- Tier 1 = ปกติ/ขายน้อย, Tier 2 = สูงขึ้น, Tier 3 = สูงสุด/ขายเยอะ

ALTER TABLE categories ADD COLUMN price_tier1 DECIMAL(12,2) DEFAULT 0;
ALTER TABLE categories ADD COLUMN price_tier2 DECIMAL(12,2) DEFAULT 0;
ALTER TABLE categories ADD COLUMN price_tier3 DECIMAL(12,2) DEFAULT 0;
