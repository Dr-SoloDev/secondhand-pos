-- Migration: Add price_tier column to purchase_order_items
-- Tracks which tier (1, 2, or 3) was used when purchasing

ALTER TABLE purchase_order_items ADD COLUMN price_tier TINYINT DEFAULT NULL COMMENT 'Tier used: 1, 2, or 3';
