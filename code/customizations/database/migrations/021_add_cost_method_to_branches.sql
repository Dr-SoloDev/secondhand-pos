-- Migration 021: Add cost_method column to branches (fifo or weighted)
ALTER TABLE branches
  ADD COLUMN cost_method ENUM('fifo', 'weighted') NOT NULL DEFAULT 'fifo'
  COMMENT 'FIFO = เข้าก่อนตัดก่อน, Weighted = ถัวเฉลี่ย';
