-- Migration 026: เพิ่ม actual_revenue ใน sale_lots
-- ยอดที่ได้จริงจากบิลศูนย์รับซื้อ/โรงงาน (กรอกหลังขาย Lot จริงๆ)
-- actual_revenue แยกจาก total_amount (ยอดประมาณการเดิม)

ALTER TABLE sale_lots
  ADD COLUMN actual_revenue DECIMAL(12,2) DEFAULT NULL AFTER total_amount,
  ADD COLUMN actual_revenue_note VARCHAR(500) DEFAULT NULL AFTER actual_revenue,
  ADD COLUMN actual_revenue_date DATE DEFAULT NULL AFTER actual_revenue_note;

-- actual_profit = ยอดจริง - ต้นทุน (คำนวณ query time ไม่ใช่ GENERATED เพราะ nullable)
