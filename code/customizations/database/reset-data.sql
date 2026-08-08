-- ============================================================================
-- reset-data.sql
-- ============================================================================
-- จุดประสงค์:
--   ล้างข้อมูลทดสอบ/seed ทั้งหมด (demo sellers, PO 5 ใบ, สินค้าทดลอง,
--   demo users, หมวดสินค้า 14 หมวด, ราคา default ใน catalog, log/audit)
--   ให้ระบบกลับสู่สภาพ "พร้อมใช้งานจริง" — ตัวเลข/เลขที่เอกสารเริ่มนับ 1 ใหม่
--   (AUTO_INCREMENT ถูกรีเซ็ตโดย TRUNCATE)
--
-- วิธีใช้:
--   รันเพียงครั้งเดียว หลัง init DB ใหม่ (docker compose down -v + up
--   ซึ่งจะรัน migrations 001-069 และ seed อัตโนมัติ) บน local และ production:
--
--     docker compose exec -T db sh -lc \
--       'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root pos_system < /path/reset-data.sql'
--
--   สคริปต์ปลอดภัยต่อการรันซ้ำ (idempotent): TRUNCATE ตารางเปล่าทำได้เสมอ,
--   procedure helper ถูก DROP ทิ้งตอนจบ, DELETE users demo ไม่มีผลเมื่อเหลือ
--   แค่ admin
--
-- คำเตือน:
--   1. ⚠️ ห้ามวางไฟล์นี้ในโฟลเดอร์ migrations/ — มันคือสคริปต์รันมือครั้งเดียว
--      ไม่ใช่ migration ถ้าใส่ใน migrations/ มันจะถูกรันทุกครั้งที่ init DB
--      และจะล้างข้อมูลที่เพิ่งคีย์ใหม่ทิ้งอยู่เรื่อย ๆ
--   2. ⚠️ สคริปต์นี้ล้างข้อมูลถาวร (TRUNCATE) — ก่อนรันบน production
--      ควร backup (mysqldump) ไว้เสมอ แม้ตั้งใจให้รันหลัง init ใหม่
--   3. ตารางที่คงไว้ (ห้ามแตะ): schema_migrations, settings,
--      item_conditions, users (ลบเฉพาะ demo users เท่านั้น)
--   4. หลังรัน: เหลือสาขาแค่ BR01/BR02 (ข้อมูลว่างรอตั้งจริง) เหลือผู้ใช้แค่
--      admin — ต้องตั้งรหัสผ่าน admin และตั้งชื่อ/ที่อยู่สาขาจริงก่อนใช้งาน
--
-- Robustness (ทำไมใช้ procedure + prepared statement):
--   ตารางที่ migration 037-069 สร้าง (login_attempts, token_blocklist,
--   employees, branch_stock, cash_sessions, adjustment_documents ฯลฯ)
--   อาจยังไม่มีใน DB ที่ migrations ยังรันไม่ครบ (เช่น ค้างที่ 036)
--   การ TRUNCATE ตรง ๆ จะ error "Table doesn't exist" → ใช้ helper
--   reset_truncate_if_exists() ตรวจ information_schema ก่อน TRUNCATE
--   เฉพาะตารางที่มีอยู่จริง — รันได้ทั้งบน DB ที่ migrations ครบและไม่ครบ
--   โดยไม่ต้องพึ่ง mysql --force ที่อาจกลบ error จริงของคำสั่งอื่น
-- ============================================================================

USE pos_system;

-- ปิด FK checks ตลอดช่วงล้างข้อมูล (ป้องกัน error ลำดับ TRUNCATE ลูก/แม่)
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 0) Helper: TRUNCATE เฉพาะตารางที่มีอยู่จริง (รองรับ DB ที่ migrations ไม่ครบ)
-- ----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reset_truncate_if_exists;
DELIMITER $$
CREATE PROCEDURE reset_truncate_if_exists(IN tbl_name VARCHAR(64))
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name   = tbl_name
    ) THEN
        SET @reset_sql = CONCAT('TRUNCATE TABLE `', tbl_name, '`');
        PREPARE reset_stmt FROM @reset_sql;
        EXECUTE reset_stmt;
        DEALLOCATE PREPARE reset_stmt;
    END IF;
END$$
DELIMITER ;

-- ----------------------------------------------------------------------------
-- 1) ล้างตารางธุรกรรม / เอกสาร (เรียงลูก → แม่ เพื่อรองรับ MySQL รุ่นที่
--    TRUNCATE กับ FK ลำดับยังสำคัญ)
-- ----------------------------------------------------------------------------

-- เอกสารซื้อของ (PO) + รูป + คำขอยกเลิก
CALL reset_truncate_if_exists('purchase_order_photos');
CALL reset_truncate_if_exists('purchase_order_items');
CALL reset_truncate_if_exists('purchase_order_cancellation_requests');
CALL reset_truncate_if_exists('purchase_orders');

-- การขาย (ขายหน้าร้าน + ขายเป็นล็อต)
CALL reset_truncate_if_exists('sale_items');
CALL reset_truncate_if_exists('sales');
CALL reset_truncate_if_exists('purchase_items');
CALL reset_truncate_if_exists('purchases');

-- ล็อตขายของเก่า + การจัดสรรสต็อก (FIFO)
CALL reset_truncate_if_exists('sale_lot_stock_allocations');
CALL reset_truncate_if_exists('sale_lot_items');
CALL reset_truncate_if_exists('sale_lots');

-- โอนสต็อกระหว่างสาขา
CALL reset_truncate_if_exists('stock_transfer_items');
CALL reset_truncate_if_exists('stock_transfers');

-- สต็อก / รายจ่าย / เงินสด
CALL reset_truncate_if_exists('inventory_transactions');
CALL reset_truncate_if_exists('business_expenses');
CALL reset_truncate_if_exists('cash_deposit_requests');
CALL reset_truncate_if_exists('cash_movements');
CALL reset_truncate_if_exists('cash_session_events');
CALL reset_truncate_if_exists('cash_sessions');
CALL reset_truncate_if_exists('adjustment_documents');

-- ระบบ (log / token / idempotency / พนักงาน / ลูกค้า / ซัพพลายเออร์)
CALL reset_truncate_if_exists('activity_log');
CALL reset_truncate_if_exists('backup_history');
CALL reset_truncate_if_exists('login_attempts');
CALL reset_truncate_if_exists('token_blocklist');
CALL reset_truncate_if_exists('idempotency_keys');
CALL reset_truncate_if_exists('employees');
CALL reset_truncate_if_exists('customers');
CALL reset_truncate_if_exists('suppliers');

-- ----------------------------------------------------------------------------
-- 2) ล้างตารางสินค้า / ผู้ขาย / สต็อกสาขา (ข้อมูลหลัก — ลูกค้าจะคีย์ใหม่หมด)
-- ----------------------------------------------------------------------------
CALL reset_truncate_if_exists('branch_stock');
CALL reset_truncate_if_exists('purchase_item_catalog');
CALL reset_truncate_if_exists('products');
CALL reset_truncate_if_exists('sellers');

-- ----------------------------------------------------------------------------
-- 3) รีเซ็ตสาขา: เหลือแค่ 2 สาขา (BR01/BR02) ฟิลด์ว่างรอตั้งชื่อจริงทีหลัง
--    (ลบ BR03/BR04 ทิ้ง — ลูกค้าเพิ่มสาขาใหม่ผ่าน UI ได้)
-- ----------------------------------------------------------------------------
CALL reset_truncate_if_exists('branch_settings');
CALL reset_truncate_if_exists('branches');

INSERT INTO branches (id, code, name, address, phone, manager_name, status)
VALUES
    (1, 'BR01', 'สาขา 1', '', '', '', 'active'),
    (2, 'BR02', 'สาขา 2', '', '', '', 'active');

-- ----------------------------------------------------------------------------
-- 4) ล้างหมวดสินค้า + รายการสินค้าใน catalog (ลูกค้าคีย์ใหม่ทั้งหมด)
-- ----------------------------------------------------------------------------
CALL reset_truncate_if_exists('categories');
CALL reset_truncate_if_exists('purchase_item_catalog');

-- ----------------------------------------------------------------------------
-- 5) ลบ demo users (manager-br01..br04) — เหลือแค่ admin ใช้ล็อกอินครั้งแรก
--    ⚠️ ไม่ TRUNCATE users (ต้องคง id=1 admin ไว้ + บันทึกการล็อกอิน)
-- ----------------------------------------------------------------------------
DELETE FROM users WHERE username <> 'admin';
-- หมายเหตุ: DELETE ไม่รีเซ็ต AUTO_INCREMENT (InnoDB ตั้ง AI ต่ำกว่า max(id)+1 ไม่ได้)
-- → ผู้ใช้รายใหม่จะได้ id=7 เป็นต้นไป — ไม่กระทบฟังก์ชันใด (id ผู้ใช้ไม่ใช่เลขที่แสดงต่อลูกค้า)
-- ถ้าต้องการให้ผู้ใช้เริ่มนับที่ id=2 จริง ๆ ต้อง TRUNCATE users + insert admin ใหม่
-- ซึ่งขัดกับข้อกำหนด (ห้ามแตะ users) จึงไม่ทำ

-- ----------------------------------------------------------------------------
-- 6) เก็บกวาด: ลบ helper ออกจาก DB แล้วเปิด FK checks กลับ
-- ----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reset_truncate_if_exists;

SET FOREIGN_KEY_CHECKS = 1;

-- จบสคริปต์ — ตรวจสอบผล:
--   SELECT id, code, name, address, phone, manager_name, status FROM branches;
--   SELECT id, username, role, branch_id FROM users;
--   SELECT COUNT(*) FROM categories; SELECT COUNT(*) FROM purchase_item_catalog;
