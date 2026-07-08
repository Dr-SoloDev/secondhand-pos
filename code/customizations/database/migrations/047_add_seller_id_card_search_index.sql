-- ============================================================
-- Migration: 047_add_seller_id_card_search_index.sql
-- Purpose: Add composite index on sellers(full_name, id_card)
--          to support real-time autocomplete search (G4)
--          idx_sellers_full_name already exists from migration 014
-- Date: 2026-07-07
-- ============================================================
USE pos_system;

-- Guard: skip if idx_sellers_id_card already exists (e.g. from migration 002)
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema = 'pos_system' AND table_name = 'sellers' AND index_name = 'idx_sellers_id_card');

-- id_card standalone — used in direct lookup and LIKE search
SET @sql1 = IF(@idx_exists = 0, 'ALTER TABLE sellers ADD INDEX idx_sellers_id_card (id_card)', 'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- composite — covers queries that filter/sort by both fields
SET @idx_exists2 = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema = 'pos_system' AND table_name = 'sellers' AND index_name = 'idx_sellers_search');

SET @sql2 = IF(@idx_exists2 = 0, 'ALTER TABLE sellers ADD INDEX idx_sellers_search (full_name, id_card)', 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
