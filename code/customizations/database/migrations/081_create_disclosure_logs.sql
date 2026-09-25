-- Migration 081: disclosure_logs — บันทึกการเปิดเผยข้อมูลผู้ขาย (PDPA / ตอบ จนท. ตำรวจ)
-- ทุกครั้งที่เปิดเผยข้อมูลผู้ขายให้บุคคลภายนอก ต้องมีแถวนี้
-- ไม่มี FK → log อยู่รอดแม้ seller ถูกลบ (audit ต้องไม่หาย)

CREATE TABLE disclosure_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  seller_id     INT          NOT NULL COMMENT 'sellers.id (ไม่ FK — เก็บ log แม้ลบผู้ขาย)',
  disclosed_at  DATETIME     NOT NULL,
  disclosed_by  INT          NULL     COMMENT 'users.id ผู้ดำเนินการ',
  recipient     VARCHAR(255) NOT NULL COMMENT 'ผู้รับข้อมูล เช่น สว.สทภ.1 / นายตำรวจ สน. ...',
  purpose       VARCHAR(255) NOT NULL COMMENT 'วัตถุประสงค์ เช่น ตรวจสอบของกลางคดี 147/2569',
  method        ENUM('in_person','electronic','api','other') NOT NULL DEFAULT 'other',
  items         JSON         NULL     COMMENT 'รายการที่เปิดเผย เช่น ["id_card","id_card_photo"]',
  legal_basis   VARCHAR(255) NULL     COMMENT 'ฐานกฎหมาย PDPA มาตรา 24(3)/24(4)...',
  notes         VARCHAR(1000) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dl_seller (seller_id),
  KEY idx_dl_at (disclosed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='PDPA disclosure register — บันทึกใครเปิดเผยอะไรให้ใครเมื่อไหร่เพราะอะไร';
