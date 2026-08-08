# W10 Release Gate: Access Control v3

**โครงการ:** Secondhand POS (รับซื้อของเก่า)
**วันที่ตรวจ:** 5 สิงหาคม 2569
**PRD อ้างอิง:** `PRD-Access-Control-v3.md`
**สถานะ code/test gate:** PASS
**สถานะ production launch:** NO-GO จนกว่าจะปิด launch blockers และ Owner ลงนาม

เอกสารนี้บันทึกหลักฐานการส่งมอบ W0-W10 เท่านั้น ไม่ถือเป็นการอนุมัติเปิด production โดยอัตโนมัติ

## 1. Package Status

| Package | ผล | หลักฐานสำคัญ |
|---|---|---|
| W0-W1 | PASS | role fixtures แบบ deterministic และ authorization/branch policy กลาง |
| W2 | PASS | PO cancellation approval, catalog create/update, seller tier และ negative cases |
| W3 | PASS | transfer create/receive/reversal state, destination scope และ double-action guards |
| W4 | PASS | manager Sale Lot เฉพาะสาขา, cashier inventory read-only และ forged branch scope |
| W5 | PASS | cash session/expense review, approval limit, self-approval policy |
| W6 | PASS | employees/users/settings แยก role และ branch; backup admin-only |
| W7 | PASS | permission response กลาง, menu/action guards และ direct URL redirect |
| W8 | PASS | structured append-only audit, snapshot, redaction, AND filters, pagination และ CSV |
| W9 | PASS (development) | AES-256-GCM, keyed exact-search hash, staged backfill/finalize/rollback |
| W10 | PASS (code/test) | regression, security matrix, browser smoke และ migration rehearsal ผ่าน |

## 2. Test Evidence

| Gate | ผลวันที่ 5 สิงหาคม 2569 |
|---|---|
| PHP lint | PASS บน PHP 8.2.31 ทุกไฟล์ใน `base-pos/api` และ `customizations/api` |
| Bash/JavaScript syntax | PASS สำหรับ runner, deploy scripts, API tests และ JS ที่เปลี่ยน |
| Full API regression | **405/405 passed**, exit code 0 |
| Focused SEC-03/SEC-04 | **80/80 passed** ใน `auth purchase_orders sale_lots` |
| PHPUnit 11.5.56 | 14 tests, 16 assertions; implemented 5 tests ผ่าน; incomplete เดิม 9 tests |
| Access-control browser smoke | PASS: cashier/manager/super_manager/admin บน desktop/mobile, direct URL และ audit UI; 0 issue, 0 console error |
| Risk-controls browser smoke | PASS: cash sessions, expenses, PO cancellation, transfer reversal และ Sale Lot; 0 issue |
| Purchase-flow browser smoke | PASS: seller/product flow, receipt/thermal preview และ mobile; 0 issue |
| Existing DB migration | PASS; รอบแรกเติม tracking ที่ค้างและรอบสองเป็น no-op |
| Fresh schema rehearsal | PASS จาก base schema ถึง migration `069`, รวม 68 tracked versions; ลบฐานชั่วคราวแล้ว |
| Seller encryption verify | **75 encrypted, 0 plaintext, 0 errors** หลัง full API regression |

PHPUnit incomplete 9 tests เป็นหนี้เดิมใน `FifoCalculationTest`, `NetProfitCalculationTest` และ `PreciousReceiptEnforcementTest` จึงต้องไม่รายงานว่า unit/integration coverage สมบูรณ์ แม้ implemented tests และ API regression จะผ่านทั้งหมด

## 3. Security Negative Matrix

| ID | สถานะ | หลักฐาน |
|---|---|---|
| SEC-01 forged `branch_id` | PASS | PO, Sale Lot, inventory, stock transfer, cash/expense/settings tests ปฏิเสธหรือบังคับเป็นสาขาจาก current user policy |
| SEC-02 direct URL/API | PASS | browser guard redirect และ backend `401/403`; cashier เข้า Sale Lot ไม่ได้, non-admin เข้า audit ไม่ได้ |
| SEC-03 stale JWT | PASS | เปลี่ยน password แล้ว cookie เดิมถูกยกเลิก; เปลี่ยน role/branch แล้ว cookie เดิมใช้ current DB policy ไม่ใช้ claim เก่า |
| SEC-04 repeated POST | PASS | PO และ Sale Lot ครั้งที่สองได้ HTTP 409 และคืน ID เอกสารเดิมโดยไม่สร้างเอกสารใหม่ |

## 4. Migration and Deployment Result

- Canonical migration source คือ `customizations/database/migrations/` เท่านั้น
- `docker-compose.yml` ใช้ base schema ตามด้วย canonical runner สำหรับฐานใหม่
- `deploy.sh` รัน pending migrations ทุกครั้ง ไม่ใช่เพียงตรวจว่าตาราง tracking มีอยู่
- Railway รัน base schema เฉพาะฐานว่าง แล้วรัน pending migrations ทุก startup
- Migration error ทำให้ startup/deploy หยุดทันที ไม่มีการ suppress ด้วย `|| true`
- Runner รองรับ `DB_NAME` จาก environment และ normalize legacy `006p` เป็น version `006`
- Development database มี tracking 68 versions (`001` ถึง `069`)

## 5. Production Launch Blockers

รายการต่อไปนี้ต้องปิดก่อนเปลี่ยนสถานะเป็น GO:

- [ ] ตั้ง `APP_ENV=production`
- [ ] สร้าง `SELLER_ID_ENCRYPTION_KEY` แยกจาก `JWT_SECRET` ด้วย `openssl rand -hex 32` และเก็บใน secret manager/backup procedure
- [ ] recreate web container หลังตั้ง key และรัน `--verify --require-finalized`
- [ ] เปลี่ยน default credential `admin/admin` เป็นรหัสผ่านเฉพาะที่แข็งแรง
- [ ] สร้างและตรวจสอบ production database backup ก่อน migration/finalize
- [ ] ทำ staged rollout ตาม `runbooks/SELLER-ID-ENCRYPTION.md`
- [ ] ยืนยัน HTTPS, firewall และ secret handling ตาม production checklist
- [ ] Owner ตรวจ acceptance checklist และลงนามด้านล่าง

ห้ามใช้ development fallback ที่ derive encryption key จาก `JWT_SECRET` ใน production และห้าม rotate/remove key เดิมก่อนตรวจว่า ciphertext ทั้งหมด decrypt ได้และ backup ใช้งานได้

## 6. Owner Acceptance

ข้าพเจ้ายืนยันว่าได้ตรวจ permission matrix, branch scope, self-approval policy, audit retention scope, seller ID-card handling และ launch blockers ข้างต้นแล้ว

**ผลการตัดสินใจ:** [ ] GO  [ ] NO-GO
**ชื่อ Owner:** ______________________________
**วันที่:** ______________________________
**ลายเซ็น:** ______________________________

**สถานะปัจจุบัน:** ยังไม่ได้ลงนาม
