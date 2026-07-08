-- ============================================================
-- Migration: 047_add_seller_id_card_search_index.sql
-- Purpose: Add composite index on sellers(full_name, id_card)
--          to support real-time autocomplete search (G4)
--          idx_sellers_full_name already exists from migration 014
-- Date: 2026-07-07
-- ============================================================
USE pos_system;

-- id_card standalone — used in direct lookup and LIKE search
ALTER TABLE sellers ADD INDEX idx_sellers_id_card (id_card);

-- composite — covers queries that filter/sort by both fields
ALTER TABLE sellers ADD INDEX idx_sellers_search (full_name, id_card);
