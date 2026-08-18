-- ============================================================
-- clean-start-cash-v21.sql
-- ⚠️ CLEAN START — WF-06 v2.1 (Owner approved 2026-08-18)
-- ล้าง cash position data ทั้งหมด + ตั้ง baseline 0 ทุกสาขา
--
-- 💥 อันตราย: ตัวเลขการเงินเดิม (cash_sessions/movements/deposits) จะหาย
-- ✅ ต้อง: (1) backup DB เรียบร้อย (2) รันด้วยมือเท่านั้น (3) รันครั้งเดียว
-- 🛡️ ไม่แตะตารางอื่น: sellers / purchase_orders / sale_lots / inventory ... ครบ
--
-- วิธีรัน: docker compose exec -T db sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
--   mysql -u root pos_system < /path/clean-start-cash-v21.sql'
-- ============================================================

-- 1) ล้างข้อมูล cash ทั้งหมด (ตามลำดับ FK — ข้อมูลเดิมไม่มีทางกู้คืนหลัง commit)
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE cash_deposit_requests;
TRUNCATE TABLE cash_movements;
TRUNCATE TABLE cash_sessions;
TRUNCATE TABLE cash_position_baselines;
SET FOREIGN_KEY_CHECKS = 1;

-- 2) ตั้ง baseline 0 ทุกสาขา (clean start — เริ่มนับเงินจากศูนย์)
INSERT INTO cash_position_baselines (branch_id, effective_date, drawer_balance, reserve_balance, note, created_by)
SELECT b.id, CURDATE(), 0, 0, 'clean start v2.1 (Owner approved 2026-08-18)', 1
FROM branches b
WHERE NOT EXISTS (SELECT 1 FROM cash_position_baselines x WHERE x.branch_id = b.id);

-- 3) หลักฐาน (verify หลังรัน)
-- SELECT id, name, 'baseline 0' AS status FROM branches;
