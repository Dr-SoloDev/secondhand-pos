-- Migration 082: retention — กำหนดอายุเก็บรูปบัตร/เอกสารผู้ขาย
-- ป้องกัน "เก็บถาวรไม่มีกำหนด" = ความเสี่ยง PDPA data minimisation
-- retain_until = วันที่ต้องลบ/ทำลาย id_card_photo + เอกสารอ้างอิงของผู้ขายรายนั้น

ALTER TABLE sellers
  ADD COLUMN retain_until DATE NULL
    COMMENT 'กำหนดลบ/ทำลายรูปบัตร+เอกสารเมื่อถึงวันนี้; null=ยังไม่กำหนด' AFTER id_card_photo,
  ADD COLUMN retain_reason VARCHAR(255) NULL
    COMMENT 'เหตุผลที่กำหนดอายุ เช่น ตามนโยบาย 1 ปีหลังทำธุรกรรมสุดท้าย' AFTER retain_until;

-- ช่วยค้นผู้ขายที่ใกล้ถึงกำหนดลบ (ยังไม่ auto-delete — มีงาน monitor แยก)
CREATE INDEX idx_sellers_retain_until ON sellers (retain_until);
