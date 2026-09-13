-- Migration 075: Enforce FIFO costing for every branch
-- Business policy: all branches use FIFO.  Existing sale-lot allocation rows
-- are historical records and are intentionally left unchanged.

-- Normalize any branch values written by earlier tests or manual edits before
-- tightening the column constraint.
UPDATE branches
SET cost_method = 'fifo'
WHERE cost_method IS NULL OR cost_method <> 'fifo';

-- Prevent future direct SQL/API writes from introducing the unsupported
-- weighted method.  Migration 021 guarantees that this column exists.
ALTER TABLE branches
  MODIFY COLUMN cost_method ENUM('fifo') NOT NULL DEFAULT 'fifo'
  COMMENT 'FIFO = เข้าก่อนตัดก่อน (นโยบายทุกสาขา)';
