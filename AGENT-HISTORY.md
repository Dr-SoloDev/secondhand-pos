# 📚 Agent History — Secondhand POS
**Archive ของ session logs — ไม่โหลดทุก session**
**ดูเมื่อ:** ต้องการ context ย้อนหลัง หรือ debug พฤติกรรมเก่า

---

## 2026-05-28 — Code Review & Hardening

### สิ่งที่ทำ
- Review opencode changes ~3,800 บรรทัด, 33 ไฟล์
- แก้ Critical 3 + High 4 + Medium 3 + bonus 1

### Critical fixes
- **C1** PurchaseOrder ref_no: prefix `PO-B{branch_id}-YYYYMMDD-NNN`
- **C2** SaleLotsController: เพิ่ม requireAuth() + scope branch_id จาก JWT
- **C3** SaleLot update(): บังคับ status='draft', recomputeFifoCost() ตอน confirm
- **Bonus** TokenService: เพิ่ม branch_id ใน JWT payload

### ผลกระทบ
- Token เก่า (ก่อน 28 พ.ค.) ต้อง logout/login ใหม่
- Smoke test 7/7 ผ่าน

---

## 2026-06-01 — Phase 3 + P0 Hardening

### สิ่งที่ทำ
- 4 report endpoints: purchase-report, sale-lot-report, sale-lot-chart, recent-sale-lots
- Dashboard: 3 stat cards + sale lot chart + recent table
- Responsive CSS: breakpoints 768px / 576px
- Weighted average cost: per-branch cost_method ENUM (migration 021)
- TOCTOU fix: SELECT FOR UPDATE ใน SaleLot confirm/cancel
- JWT_SECRET + Docker credentials → .env
- 33 → 47 tests

### Key fixes
- PurchaseOrder::cancel() เรียก undefined updateStatus() → แก้เป็น direct SQL UPDATE
- SaleLot::deductStock() ส่ง SQL string ให้ Database::execute() → แก้เป็น query()
- SaleLot ref_no ไม่มี branch prefix → เพิ่ม SO-{BRANCH_CODE}-YYYYMMDD-NNN

---

## 2026-06-01 — Hardening Round 2

- `catch (Exception $e)` → `catch (\Throwable $e)` ใน index.php
- เพิ่ม MYSQL_USER + MYSQL_PASSWORD ใน .env / .env.example
- 47 → 58 tests (+11 edge cases)

---

## 2026-06-01 — Inventory SKU & Cost Field Fixes

- ลบช่อง "ราคาทุน" จากหน้า Inventory (ราคาทุนมาจาก PO ไม่ใช่ตอนเพิ่มสินค้า)
- category_id ส่ง null แทน "" เมื่อไม่เลือก
- SKU กรอกเองได้ (ตัวเลข 1-2-3 ไม่มี prefix)

---

## 2026-06-01 — Tier Button UX + Category Stock Card

- Tier buttons visible ตั้งแต่โหลดหน้า, default "บิล1/2/3"
- Category stock card ใน inventory.html — แสดง stock_kg + bar graph
- เพิ่ม escapeHtml() ใน inventory.js

---

## 2026-06-05 — Global Categories + UX

- Migration 024: merge categories 59 รายการ (4 สาขา) → 15 global
- Migration 025: default_unit per category
- localStorage จำสาขาที่เลือก + branch banner
- Relax price validation: บิล1 ≤ บิล2 ≤ บิล3 (equal allowed)
- TRUNCATE purchase_item_catalog (เตรียมให้ลูกค้าบันทึกใหม่ 79 รายการ)

---

## 2026-06-08 — GOALS G2-G10 Complete

- G2: บิลพิมพ์ 2 แบบ (ปกติ + โลหะมีค่า)
- G3: Blacklist alert + blacklist_reason
- G4: ค้นหาผู้ขาย real-time
- G5: Dashboard 4 สาขา
- G6: price-board.html
- G7: ประวัติผู้ขาย modal
- G8: Export CSV 4 แบบ
- G9: stock-transfers.html + audit trail
- G10: stock alert threshold
- Migrations 027-032

---

## 2026-06-09 — Demo Day UX Fixes

- Auto-fill tier ทำงาน 2 ทิศ (tier ก่อน/หลังเลือก item)
- ช่องสาขากว้าง/สูงขึ้น
- user-dropdown ชิดขวาทุกหน้า (margin-left: auto ใน layout.css)
- บิลพิมพ์ 2 ใบ landscape @page A4 landscape
