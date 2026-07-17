# 🗺️ Workflow Registry — Secondhand POS

**ผู้ดูแล:** Workflow Architect
**สร้างเมื่อ:** 2026-06-14
**Deadline ส่งงาน:** 2026-06-30 (เหลือ 16 วัน)
**Stack จริง (verified):** PHP 8.2 + MySQL 8.0 + HTML + Vanilla JS + Docker
**หลักการเดินงาน:** ทีละ workflow → architect ออกแบบ → Senior PM แปลงเป็น task → engineer ลงมือ → ตรวจ แล้วค่อยไป workflow ถัดไป (ไม่รุมทำพร้อมกัน)

---

## ⚠️ Divergence ที่จับได้ระหว่าง Discovery (ต้องระวัง)

| # | เอกสารบอกว่า | ความจริงในโค้ด | ผลถ้าเชื่อเอกสาร |
|---|---|---|---|
| D-1 | COMPLETION-REPORT ระบุ React (.jsx — Sellers.jsx, SaleLots.jsx) | จริงคือ HTML + Vanilla JS (`base-pos/admin/*.html` + `assets/js/*.js`) | วางแผน/จ้าง/เขียนผิด stack ทั้งหมด |
| D-2 | "Responsive CSS เสร็จแล้ว (768/576 breakpoints)" | responsive อยู่แค่ `layout.css` (shell) — component CSS (tables/forms/modals) มี `@media` = 0 | คิดว่าใช้บนมือถือได้ แต่จริงๆ พัง |
| D-3 | — | `layout.css` มี responsive **2 ชุดขัดกัน** (icon-rail @992 vs off-canvas @768) + breakpoint ซ้ำ/ไม่ตรงกัน 6 ค่า | sidebar/เลย์เอาต์เพี้ยนช่วงจอกลาง |
| **D-4** 🚨 | "migration ครบแล้ว 001-033" | มี migration **2 โฟลเดอร์** — runner (`run-migrations.sh`) และ Docker (`docker-compose.yml:20`) รัน**เฉพาะ** `customizations/database/migrations/` (001-023). โฟลเดอร์ `customizations/migrations/` (024-033) **ไม่เคยถูกรันอัตโนมัติเลย** | **CRITICAL:** บน fresh deploy (เครื่องลูกค้า 30 มิ.ย.) — blacklist (028), precious receipt (029), stock transfers (030), business expenses (027) **จะพังหมด** (unknown column/table) เพราะ DB จริงของ dev ถูกใส่ column ด้วยมือ แต่เครื่องใหม่ไม่มี |

### 🚨 D-4 รายละเอียด (finding สำคัญที่สุดสำหรับ deadline)

- `docker-compose.yml:20` mount เฉพาะ `./customizations/database/migrations` (สูงสุด = 023) เข้า container
- `customizations/migrations/` (024-033) อยู่นอกเส้นทางที่ทั้ง Docker และ runner มองเห็น
- เลข **023 ซ้ำกัน 2 โฟลเดอร์** (`023_add_transfer_logistics_fields` vs `023_populate_catalog_categories`) → ถ้าย้ายมารวมจะชนกัน ต้อง renumber
- ยังไม่มี `schema_migrations` table (ไม่มี migration tracking) → ไม่มีใครรู้ว่าอันไหนรันไปแล้ว
- **ผลต่อ WF-01:** migration รูปภาพต้องลงใน `customizations/database/migrations/` (DIR ที่รันจริง) เลขถัดไป — แต่ต้องเคลียร์ความซ้ำซ้อนก่อน ไม่งั้นเลขชน

### ℹ️ ของที่มีอยู่แล้วบางส่วน (เกี่ยวกับ WF-01 ถ่ายรูป)

- `sellers.id_card_photo VARCHAR(255)` — มี column เก็บ path รูปบัตรแล้ว (002, รันจริง ✅)
- `purchase_order_items.photo_path` — `PurchaseOrder.php:207` อ้างถึง (ต้องยืนยันว่า column มีจริง)
- ทั้งคู่เป็น **path เดียว** → เก็บได้รูปเดียว แต่ G1 ต้องการ **หลายรูปต่อ PO** → ต้องมีตาราง `po_photos` ใหม่ (1 PO → หลายรูป)

---

## View 1 — By Workflow (รายการหลัก)

| ID | Workflow | Spec file | Status | Trigger | Actor หลัก | Goal |
|----|----------|-----------|--------|---------|-----------|------|
| **WF-PRE** 🚨 | Migration Consolidation (รวม migration 2 โฟลเดอร์ + tracking) | WORKFLOW-PRE-migration-consolidation.md | Draft | ก่อนเริ่มงาน feature ทุกตัว / ก่อน deploy | DevOps + Database | (กันพังตอน deploy) |
| WF-00 | ~~Responsive Foundation~~ → **ยุบรวมเข้า WF-01** (เหลือแค่หน้าถ่ายรูปมือถือหน้าเดียว) | — | Deprecated | — | — | — |
| WF-01 | ถ่ายรูปสินค้า + seller (ผ่าน QR handoff: Desktop→มือถือ) | WORKFLOW-01-photo-upload.md | Missing | บันทึก PO บน Desktop → ขึ้น QR → สแกนถ่าย | Backend+Frontend | G1 |
| WF-02 | พิมพ์ใบรับซื้อ 2 แบบ | WORKFLOW-02-receipt-printing.md | Missing | กดปุ่ม "พิมพ์บิล" หลังบันทึก PO | Frontend | G2 |
| WF-03 | ค้นหา seller + Blacklist Alert | WORKFLOW-03-seller-lookup.md | Missing (API พร้อม) | พิมพ์ในช่องค้นหา seller | Frontend | G3+G4 |
| WF-04 | Dashboard 4 สาขา | WORKFLOW-04-branch-dashboard.md | Missing (API พร้อม) | เปิดหน้า Dashboard | Frontend | G5 |
| **WF-05** | **Purchase Flow UX — keyboard-first cashier redesign** | **WORKFLOW-05-purchase-flow-ux.md** (ใน `code/docs/workflows/`) | **✅ DONE** | Client demo feedback | Frontend | UX |

Status: `Approved` | `Review` | `Draft` | `Missing` | `Deprecated`
**Missing** = ยังไม่มี spec (ธงแดง) — "API พร้อม" = endpoint มีแล้วแต่ยังไม่ได้ต่อ UI/ยังไม่ได้ spec

---

## View 2 — By Component (โค้ด → workflows)

| Component | ไฟล์ | เกี่ยวกับ workflow ไหน |
|-----------|------|----------------------|
| CSS shell | `assets/css/layout.css` | WF-00 (ทุกหน้า) |
| Component CSS | `assets/css/components/*.css` | WF-00 (tables, forms, modals, purchase-orders, sale-lots) |
| common.js (sidebar/hamburger) | `assets/js/common.js:298-327` | WF-00 |
| หน้ารับซื้อ | `admin/purchase-orders.html` + `assets/js/purchase-orders.js` | WF-00, WF-01, WF-02, WF-03 |
| Router | `base-pos/api/Router.php` | ทุก workflow (จุดเข้า API) |
| Sellers API | `customizations/api/Controllers/SellersController.php` | WF-03 (`/sellers/search`, `/sellers/blacklist`) |
| Branches API | `customizations/api/Controllers/BranchesController.php` | WF-04 (`/branches/summary`) |
| PurchaseOrders API | `customizations/api/Controllers/PurchaseOrdersController.php` | WF-01, WF-02 |

---

## View 3 — By User Journey (สิ่งที่ผู้ใช้เจอ → workflows)

### พนักงานหน้าร้าน (Cashier)
| สิ่งที่ทำ | workflow เบื้องหลัง | จุดเข้า |
|----------|---------------------|---------|
| เปิดหน้ารับซื้อบนแท็บเล็ตที่เคาน์เตอร์ | WF-00 | /admin/purchase-orders.html |
| ค้นหาผู้ขายเดิมจากเลขบัตร/ชื่อ | WF-03 → (ถ้า blacklist) popup เตือน | ช่องค้นหา seller |
| ถ่ายรูปกองสินค้า + บัตรผู้ขาย | WF-01 | ปุ่มถ่ายรูปในหน้า PO |
| พิมพ์ใบรับซื้อให้ลูกค้า | WF-02 (auto-detect โลหะมีค่า) | ปุ่มพิมพ์บิล |

### เจ้าของร้าน (Owner)
| สิ่งที่ทำ | workflow เบื้องหลัง | จุดเข้า |
|----------|---------------------|---------|
| ดูยอด 4 สาขาพร้อมกัน | WF-04 | /admin/index.html (Dashboard) |

---

## View 4 — By State (สถานะ → workflows)

*(จะเติมเมื่อเขียน spec แต่ละตัว — โดยเฉพาะ PO/SaleLot/Photo lifecycle)*

| State | เข้าโดย | ออกโดย | workflow ที่เกี่ยวข้อง |
|-------|---------|--------|----------------------|
| (รอเติม WF-01: photo pending/uploaded/failed) | — | — | WF-01 |

---

## ลำดับการเดินงานที่ตกลงไว้

```
WF-00 (Responsive) → WF-01 (ถ่ายรูป) → WF-02 (พิมพ์บิล) → WF-03 (ค้นหา+blacklist) → WF-04 (dashboard)
```

**เหตุผลลำดับ:** WF-00 ต้องมาก่อน WF-01 เพราะการถ่ายรูปทำบนมือถือ/แท็บเล็ตหน้างาน — ถ้าหน้าจอยังใช้บนมือถือไม่ได้ ปุ่มถ่ายรูปก็กดใช้จริงไม่ได้

---

## กฎการดูแล Registry

- พบ workflow ใหม่หรือเขียน spec เสร็จ → อัปเดตตารางทันที
- Status `Missing` = ธงแดง ต้องยกขึ้นมาคุยรอบถัดไป
- ห้ามลบแถว — ใช้ `Deprecated` แทน เพื่อเก็บประวัติ

---

> ⚠️ **หมายเหตุ:** มี `REGISTRY.md` 2 ที่ — `docs/workflows/REGISTRY.md` (อันนี้) กับ `code/docs/workflows/REGISTRY.md` (อันใหม่กว่า, มี WF-05)  
> **ควรย้ายไปใช้ `code/docs/workflows/REGISTRY.md` เป็นหลัก** — อันนี้เป็น legacy ที่ยังไม่ได้ merge
