# QA — Access Control P0-1..P0-6 Verification Guide

**โปรเจกต์:** Dr-SoloDev/secondhand-pos  **วันที่:** 5 ส.ค. 2569  **Branch:** `feat/p0-access-control`

> งาน implement ครบแล้ว (3 commits) — ขั้นตอนนี้คือ **QA ก่อน merge เข้า main** ตาม `rules` SoloCorp: QA ก่อน deploy เสมอ

---

## 1. สิ่งที่เปลี่ยน (3 commits)

| commit | เนื้อหา |
|--------|--------|
| `344075bb` | P0-2/P0-3/P0-4 — cashier เพิ่มสิทธิ์ (catalog, seller tier, stock transfer) |
| `7d6948c1` | P0-1/P0-5 — manager อนุมัติภายในสาขาตัวเอง (PO cancel + cash session) + `test_role_permissions.sh` |
| `58c491a6` | P0-6 — `common.js` sidebar ตาม role matrix จริง (`PAGE_ROLE_ACCESS`) |

## 2. ไฟล์ที่แก้ (8)

```
code/customizations/api/Controllers/StockTransfersController.php      (P0-4)
code/customizations/api/Controllers/PurchaseItemCatalogController.php (P0-2)
code/customizations/api/Controllers/SellersController.php             (P0-3)
code/customizations/api/Controllers/PurchaseOrdersController.php      (P0-1)
code/customizations/api/Controllers/CashSessionsController.php        (P0-5)
code/customizations/api/Models/PurchaseOrderCancellation.php          (P0-1: getRequestBranch)
code/customizations/api/Models/CashSession.php                        (P0-5: getBranch)
code/base-pos/assets/js/common.js                                     (P0-6)
code/tests/api/test_role_permissions.sh                               (test ใหม่)
```

## 3. Role matrix ที่คาดหวัง (เทียบ API จริง)

| หน้า / endpoint | cashier | manager | super_manager | admin |
|---|---|---|---|---|
| users / settings | ❌ | ❌ | ❌ | ✅ |
| branches, expenses, stock-transfers, price-board, catalog | ✅ | ✅ | ❌* | ✅ |
| employees | ❌ | ✅ | ✅ | ✅ |
| financial-summary | ❌ | ✅ | ✅ | ✅ |
| cash-sessions (เปิด-ปิดยอด + อนุมัติสาขาตัวเอง) | ✅ | ✅ | ✅ | ✅ |
| PO cancel / cash session approval (ข้ามสาขา) | ❌ | ❌ 403 | ✅ | ✅ |

*super_manager เห็นเฉพาะ financial-summary + cash-sessions (ตาม PRD v2 P0-6)

## 4. ขั้นตอน QA (รันบนเครื่องที่มี PHP + MySQL + server)

### 4.1 Pull branch
```bash
cd /path/to/secondhand-pos
git fetch origin
git checkout feat/p0-access-control
```

### 4.2 Syntax check ไฟล์ที่แก้
```bash
php -l code/customizations/api/Controllers/StockTransfersController.php
php -l code/customizations/api/Controllers/PurchaseItemCatalogController.php
php -l code/customizations/api/Controllers/SellersController.php
php -l code/customizations/api/Controllers/PurchaseOrdersController.php
php -l code/customizations/api/Controllers/CashSessionsController.php
php -l code/customizations/api/Models/PurchaseOrderCancellation.php
php -l code/customizations/api/Models/CashSession.php
```

### 4.3 API tests (ต้องมี server รันที่ localhost:8080 + DB)
```bash
bash tests/api/run.sh          # ควร auto-include test_role_permissions.sh
# ถ้าไม่รวม ให้รันตรง:
bash tests/api/test_role_permissions.sh
```
คาดหวัง: test ใหม่ P0-2/P0-3/P0-4 (cashier ได้) + P0-1/P0-5 (branch mismatch → 403) ผ่านทั้งหมด และ test เดิมไม่ regression

### 4.4 Manual UI check (login ทีละ role แล้วดู sidebar)

| role | ต้องเห็น | ต้องไม่เห็น |
|---|---|---|
| cashier | เปิด-ปิดยอด, แคตตาล็อก, โอนสต็อก, บอร์ดราคา, รายจ่าย, สาขา | ผู้ใช้, ตั้งค่า, พนักงาน, สรุปการเงิน |
| manager | + พนักงาน, รายจ่าย, สรุปการเงิน | ผู้ใช้, ตั้งค่า |
| super_manager | สรุปการเงิน, เปิด-ปิดยอด | เมนูอื่น non-admin |
| admin | ทั้งหมด | — |

บัญชี test: `admin`/`admin` • `manager-br01..04`/`admin` • `cashier-br02`/`CashierTest1234`

## 5. หลัง QA ผ่าน

```bash
git checkout main && git merge feat/p0-access-control
```
(หรือเปิด PR — ตามที่ Owner ถนัด)

## 6. หมายเหตุ

- เนื้อหาที่ push เป็น full-file snapshot ที่ reconstruct จาก source จริง — ถ้า `git diff` หลัง pull พบส่วนที่ไม่ตรง ให้แจ้งก่อน merge
- ห้ามแตะ logic เงิน/สต็อกในรอบนี้ — ทุก commit เป็น permission scope เท่านั้น
