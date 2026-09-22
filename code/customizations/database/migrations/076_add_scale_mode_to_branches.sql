-- Migration 076: Scale mode per branch (Tiger TI-01)
-- disabled = คีย์มืออย่างเดียว
-- auto     = เสียบสาย RS232 + กดเชื่อมตาชั่ง (Web Serial) → ล็อค auto, ไม่เจอ fallback คีย์มือ (default — เสียบแล้วเปิด)
-- required = บังคับตาชั่งเท่านั้น (ปิดไว้ก่อน เผื่ออนาคต)
ALTER TABLE branches
  ADD COLUMN scale_mode ENUM('disabled','auto','required') NOT NULL DEFAULT 'auto'
  COMMENT 'disabled=คีย์มือ, auto=เสียบแล้วเปิด (Web Serial), required=บังคับตาชั่ง' AFTER cost_method;

-- Default auto — เสียบสาย USB-RS232 + กดเชื่อมตาชั่งครั้งเดียว → ใช้ได้เลย ไม่ต้องตั้งค่าต่อสาขา
UPDATE branches SET scale_mode = 'auto' WHERE scale_mode IS NULL OR scale_mode = '';
