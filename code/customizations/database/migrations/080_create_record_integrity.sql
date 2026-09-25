-- Migration 080: record_integrity — append-only hash chain ตรวจแก้ไขย้อนหลัง (tamper-evidence)
-- 1 entity (เช่น seller) = 1 chain; chain_hash = HMAC(key, prev_hash|payload_hash|meta)
-- key derive จาก SELLER_ID_ENCRYPTION_KEY + domain "integrity-v1" (กัน brute-force)
-- genesis prev_hash = 64 zeros; backfill ใช้ reason='backfill'

CREATE TABLE record_integrity (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type   VARCHAR(32)  NOT NULL COMMENT 'seller, purchase_order, ...',
  entity_id     INT          NOT NULL,
  seq           INT          NOT NULL COMMENT 'ลำดับใน chain ของ entity นี้ (เริ่ม 1)',
  action        VARCHAR(32)  NOT NULL COMMENT 'create, update, delete, backfill, disclosure',
  reason        VARCHAR(64)  NULL     COMMENT 'เหตุผลเพิ่มเติม เช่น backfill',
  payload_hash  CHAR(64)     NOT NULL COMMENT 'sha256(canonical JSON ของ entity snapshot)',
  prev_hash     CHAR(64)     NOT NULL COMMENT 'chain_hash ของ seq-1 (genesis = 64 zeros)',
  chain_hash    CHAR(64)     NOT NULL COMMENT 'HMAC-SHA256(key, prev_hash|payload_hash|action|seq|entity)',
  actor         VARCHAR(64)  NULL     COMMENT 'username ที่กระทำ',
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ri_entity_seq (entity_type, entity_id, seq),
  KEY idx_ri_entity (entity_type, entity_id),
  KEY idx_ri_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only audit chain — ห้าม UPDATE/DELETE แถวเดิม';
