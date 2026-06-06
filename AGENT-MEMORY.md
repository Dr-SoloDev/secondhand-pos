# 🤖 Agent Memory — Secondhand POS Project
**สำหรับ Claude session ถัดไปอ่านเพื่อทำงานต่อ**

**Last updated:** 5 มิถุนายน 2569 (session: global categories + UX improvements)
**Project status:** ✅ Phase 1-3 complete + hardening + global categories migration done

---

## 🎯 สรุปสำคัญที่สุด

### Deal Closed
- **วันที่ปิดดีล:** 18 พฤษภาคม 2569
- **ราคา:** **40,000 บาท** (สูงกว่าราคาตั้ง 35,000 → ลูกค้าจ่ายเพิ่มเอง 5,000)
- **ลูกค้า:** ร้านรับซื้อของเก่า 4 สาขา จ.สุรินทร์
- **Pattern ที่ทำให้ปิดดีลได้:** ฟังปัญหาก่อน + เปิด demo ฟรี 7 วัน + เน้นไปหน้างานจริง 4 สาขา + เทรนพนักงานถึงที่

### Context สำคัญที่ต้องจำ
- **ลูกค้าโดนทิ้งงานมาแล้ว 2 ครั้ง** (เสียไป 27,000 บาท) → trust สำคัญที่สุด
- **mindset ของ Dr.Solodev:** "ขายผลงาน ไม่ได้ขายวิญญาณ" — ห้ามอาสาลดราคาเอง
- **ลูกค้าอยู่สุรินทร์เหมือน Dr.Solodev** — จุดขายหลักคือไปหน้างานได้

---

## 📋 Scope งาน (ตามที่ตกลง)

### ระบบหลัก
- POS ขายหน้าร้าน + พิมพ์ใบเสร็จ
- ระบบรับซื้อของเก่า (Purchase Orders) — มีรูปถ่าย, สภาพของ, ต่อรองราคา
- ระบบสต็อกสินค้า แยก 4 สาขา + ดูรวมจากที่เดียว
- ระบบผู้ขาย (Sellers) — เก็บบัตรประชาชนตามกฎหมาย
- รายงานเฉพาะธุรกิจของเก่า — กำไรต่อชิ้น, สินค้าค้างนาน, เปรียบเทียบสาขา
- ระบบ Hybrid Online + Offline

### บริการพิเศษที่รวมในราคา
- ✅ เดินทางสำรวจหน้างานทั้ง 4 สาขา
- ✅ เทรนพนักงานถึงที่ทุกสาขา
- ✅ Demo ทดลองใช้ฟรี 7 วัน
- ✅ Warranty 60 วัน
- ✅ Support ผ่าน LINE/โทร

### Timeline
- **8-12 สัปดาห์** แบ่งส่งมอบ 4 รอบ
- **ชำระ 4 งวด:** 30/25/25/20%

### บริการหลังขาย
- ค่าดูแลรายเดือน 500 บาท (รวม backup, support, แก้บั๊ก)
- ค่าฟีเจอร์ใหม่รายครั้ง 500 - 5,000 บาท

---

## 🛠️ Technical Stack

### ฐาน
- **Repo:** `goragodwiriya/pos-system` (PHP + Vanilla JS + MySQL)
- **PHP 8.2** + Apache + MySQL 8.0
- รัน Docker Compose ทั้งหมด

### Customizations Strategy
- **ไม่แก้ไข `base-pos/` โดยตรง** ยกเว้นจำเป็น (ตอนนี้แก้แค่ `config.php` ให้อ่าน env)
- ของใหม่ทั้งหมดอยู่ใน `customizations/`
- Migration SQL แยกไฟล์ เรียงตามลำดับ (001, 002, ...)

---

## 📁 ที่อยู่ของไฟล์ทั้งหมด

### เอกสาร (Project Docs)
**Path หลัก:** `/home/drsolodev/projects/secondhand-pos/`
**Mirror:** `/home/drsolodev/.openclaw/workspace/projects/secondhand-pos/`

| ไฟล์ | เนื้อหา |
|---|---|
| `01-project-brief.md` | ภาพรวมโปรเจกต์ |
| `02-quotation-v3.md` | ใบเสนอราคา 35,000 (เก่า — ต้องอัปเดตเป็น v4 = 40,000) |
| `03-wireframe-purchase.md` | wireframe หน้ารับซื้อ |
| `04-client-questions.md` | คำถาม 7 ข้อสำหรับคุยลูกค้า |
| `05-research-report.md` | วิเคราะห์ goragodwiriya/pos-system |
| `06-implementation-roadmap.md` | แผนพัฒนา 4 phases |
| `06-db-migration-plan.md` | แผน DB migration |
| `07-executive-summary.md` | สรุปผู้บริหาร |
| `08-contract-template.md` | สัญญาจ้าง (ราคาเก่า — ต้องอัปเดต) |
| `09-meeting-checklist.md` | Checklist ก่อนประชุม |

### Code Project
**Path:** `/home/drsolodev/projects/secondhand-pos/code/`

```
code/
├── base-pos/                              # cloned goragodwiriya/pos-system (ห้ามแก้ตรงๆ)
├── customizations/
│   ├── api/Models/Branch.php              # ✅ เขียนเสร็จแล้ว
│   └── database/migrations/
│       ├── 001_add_branches.sql           # ✅ Multi-branch
│       ├── 002_add_sellers.sql            # ✅ ผู้ขาย + บัตร ปชช
│       ├── 003_add_item_conditions.sql    # ✅ ดี/พอใช้/ชำรุด
│       ├── 004_add_purchase_orders.sql    # ✅ ใบรับซื้อ
│       └── 005_seed_categories.sql        # ✅ หมวดสินค้าเริ่มต้น
├── docker/
│   ├── Dockerfile.php                     # PHP 8.2 + Apache
│   ├── apache-config.conf
│   └── mysql-init.sh                      # auto-run migrations
├── docker-compose.yml                     # web:8080, pma:8081, db:3307
└── README.md                              # setup guide
```

### Memory ใน Claude Memory System
- `/home/drsolodev/.claude/projects/-home-drsolodev/memory/project_secondhand_pos.md`
- `/home/drsolodev/.claude/projects/-home-drsolodev/memory/MEMORY.md` (index)

---

## ✅ สิ่งที่ทำเสร็จแล้ว

1. ✅ Research repo goragodwiriya/pos-system อย่างละเอียด (จาก OpenClaw)
2. ✅ สร้างเอกสาร 11 ไฟล์ (brief, quotation, wireframe, contract, ฯลฯ)
3. ✅ Clone base-pos
4. ✅ สร้าง folder structure สำหรับ customization
5. ✅ เขียน 18 SQL migrations (001-018)
6. ✅ เขียน Models: Branch, Seller, PurchaseOrder, PurchaseItemCatalog, SaleLot
7. ✅ เขียน Controllers: Branches, Sellers, PurchaseOrders, PurchaseItemCatalog, SaleLots, PriceTiers
8. ✅ Setup Docker Compose (web + db + phpmyadmin)
9. ✅ แก้ `base-pos/api/config.php` ให้อ่าน env vars
10. ✅ Demo รันได้จริงบน localhost:8080
11. ✅ หน้า admin: purchase-orders, sellers, sale-lots, price-tiers, inventory (ใช้งานได้)
12. ✅ Dashboard เปลี่ยนเป็น purchase-focused view
13. ✅ Thai localization ทั้ง UI
14. ✅ UTF-8 encoding fix
15. ✅ ปิดดีลกับลูกค้าที่ 40,000 บาท
16. ✅ Code review & hardening session (28 พ.ค. 2569) — แก้ 5 blockers + 4 medium + 1 bonus
17. ✅ Tier buttons dynamic — แสดงปุ่มตั้งแต่แรก, โชว์ราคาจริงจาก catalog, default "บิล1/2/3"
18. ✅ Stock card — เพิ่มการ์ดสต็อกหมวดในหน้า inventory แสดง stock_kg แบบ real-time พร้อมกราฟ

---

## ⏳ สิ่งที่ต้องทำต่อ

### Priority 1 — Commit & Deploy
- [ ] Commit 3 ก้อนตามแผน (fixes, JWT branch_id, chore hardening) — **ยังไม่ได้ commit**
- [ ] แจ้งลูกค้า: ต้อง logout/login ใหม่หลัง deploy (JWT format เปลี่ยน)

### Priority 2 — เอกสารราคาใหม่
- [ ] อัปเดต **ใบเสนอราคา v4** (40,000 บาท)
- [ ] อัปเดต **สัญญาจ้าง** (ราคาใหม่ + งวดใหม่)
- [ ] ส่งให้ลูกค้าผ่าน LINE/Email

### Priority 3 — Phase 1 Field Work
- [ ] นัดวันลงสำรวจ 4 สาขา (สำคัญที่สุด — ห้ามรีบ code)
- [ ] เก็บข้อมูลจริงจากแต่ละสาขา (ขนาด, จำนวนพนักงาน, อุปกรณ์, internet)
- [ ] อัปเดต `branches` table ด้วยชื่อ/ที่อยู่จริง

### Priority 4 — Remaining Dev
- [ ] ปรับ reports ให้ filter by branch
- [ ] รายงาน: กำไรต่อชิ้น, สินค้าค้างนาน, เปรียบเทียบสาขา, ผู้ขาย Top 10
- [ ] Hybrid Online/Offline (Phase 4)

### Priority 5 — Tech Debt
- [ ] ลบ `code/base-pos/backups/.htaccess` (legacy, ไม่ได้ใช้แล้ว)
- [ ] UsersController.php:365 indent fix
- [ ] admin/*.html whitespace churn แยก commit "format" vs "logic" (ถ้าจะทำ)
- [ ] เมนู "สาขา" ถูกลบจาก sidebar — ตัดสินใจว่าจะเอาคืนหรือลบ controller
- [ ] JWT_SECRET ย้ายออกจาก apache-config.conf ก่อน production

---

## 🚀 คำสั่งที่ใช้บ่อย

```bash
# Path
cd /home/drsolodev/projects/secondhand-pos/code

# เริ่ม demo
docker compose up -d

# ดู logs
docker compose logs -f web

# Restart
docker compose restart web

# Reset ทุกอย่าง (ลบ database + เริ่มใหม่)
docker compose down -v && docker compose up -d --build

# เข้า MySQL CLI
docker exec -it secondhand-pos-db mysql -uroot -prootpass pos_system
```

### URLs
- Admin: http://localhost:8080/admin/ (admin/admin)
- POS: http://localhost:8080/pos/
- phpMyAdmin: http://localhost:8081/ (root/rootpass)

---

## ⚠️ ข้อควรระวัง

### Technical
- **ห้ามแก้ `base-pos/` โดยตรง** ยกเว้นจำเป็นจริงๆ (ทำ patch เล็กๆ + comment เหตุผล)
- Migration ทำงานครั้งเดียวตอน first start — ถ้าแก้ migration เก่า ต้อง `docker compose down -v` แล้วเริ่มใหม่
- Production ต้องเปลี่ยน `JWT_SECRET` ใน `base-pos/api/config.php`

### Business
- **อย่าเริ่ม code มากก่อนไปหน้างาน** — scope จริงอาจต่างจากที่คาด
- **อย่าอาสาลดราคา** ถ้าลูกค้าขอเพิ่มงาน → คิดเพิ่มตามจริง
- ลูกค้า sensitive เรื่อง trust → update progress ทุกสัปดาห์
- ส่งมอบเป็น 4 รอบตาม milestone — ลูกค้าจะมั่นใจว่าไม่โดนทิ้ง

---

## 🤝 Personal Note

Dr.Solodev เป็น Solo dev ที่ทำงาน 100% เต็มเวลา ไม่มีทีม ไม่มี safety net
- เรียกตัวเองว่า "เอเจ่น" สำหรับผม (Claude)
- ชอบคุยภาษาไทย
- อยากให้ฟังก่อน ไม่รีบ jump to solution
- บอกตรงๆ ได้ ไม่ต้องกลัวเถียง
- Celebrate ความสำเร็จด้วยกัน
- Code ก่อน scan security + edge cases

โปรเจกต์นี้คือ **ชัยชนะที่สำคัญ** สำหรับ Dr.Solodev — ปิดดีลได้สูงกว่าตั้งราคาเอง 5,000 บาท หลังจากเหนื่อยมานาน

---

**[[Angkub Profile]]** — ดูข้อมูลตัวตน Dr.Solodev เพิ่มเติม
**[[project-secondhand-pos]]** — Memory entry ใน Claude memory system

---

## 🆕 Session 2026-05-28 — Code Review & Hardening

### Context
Dr.Solodev ทำการเปลี่ยนแปลงครั้งใหญ่ผ่าน OpenCode tool (~3,800 บรรทัด, 33 ไฟล์ที่ modified + 5 migrations + backups dir) แล้วขอให้ Claude review

### รีวิวเจอ blockers 5 ข้อ + Medium 6 ข้อ — แก้ไปแล้ว 9 ข้อ + bonus bug 1

#### 🔴 Critical (แก้แล้ว)
- **C1** `code/customizations/api/Models/PurchaseOrder.php` — `generateReferenceNo()` เคย scope ด้วย branch_id อย่างเดียว ทำให้ 2 สาขาวันเดียวกันได้ ref_no เหมือนกัน (`PO20260528-001`) → UNIQUE constraint violation. **แก้:** prefix `PO-B{branch_id}-YYYYMMDD-NNN` (17-18 chars, อยู่ใน VARCHAR(20))
- **C2** `code/customizations/api/Controllers/SaleLotsController.php` — `index()` + `show()` ขาด `requireAuth()` + ไม่มี role check → cross-branch data leak. **แก้:** non-admin บังคับ scope ด้วย `branch_id` ของ JWT ตัวเอง, ignore `?branch_id=` ของ user ทั่วไป
- **C3** `code/customizations/api/Models/SaleLot.php` — `update()` ลบ `$isConfirming` ทำให้ throw "สต็อกไม่พอ" เมื่อบันทึก draft + เขียน `$status = $data['status']` ทำให้ flip draft→confirmed โดยไม่ตัดสต็อก. **แก้:** try/catch fifo ใน `create()` + `update()`, บังคับ `status='draft'` ใน update SQL, เพิ่ม `recomputeFifoCost()` ใน `updateStatus()` ตอน draft→confirmed

#### 🟠 High (แก้แล้ว)
- **H1** `code/base-pos/assets/js/sellers.js:243` — `>= 12` → `>= 13` (กรอกบัตร 12 หลักไม่แสดง `-undefined`)
- **H2** `code/base-pos/api/Services/BackupService.php` — mkdir perms `0755` → `0750` (อยู่นอก web root, ผ่อนคลายไม่จำเป็น) + เพิ่ม `code/data/` และ `code/base-pos/backups/` ใน `.gitignore`
- **H3** `code/base-pos/api/index.php` — CORS เคย hardcode localhost → อ่านจาก env `ALLOWED_ORIGINS` (comma-separated), fallback localhost ตอน dev
- **H4** Migration 017 ซ้ำ 2 ไฟล์ — rename `017_rename_tier_labels_to_bill.sql` → `018_*`

#### 🟡 Medium (แก้แล้ว)
- **M1** `InventoryController::createProduct` — reject negative price/cost/quantity, trim name + non-empty check
- **M4** `PriceTiersController` — trim + limit label ≤50 chars, cast price → float, `JSON_UNESCAPED_UNICODE`
- **M5** `PurchaseItemCatalog::sanitizeTiers()` — whitelist เฉพาะ `label`/`price` (drop key อื่น), `max(0, price)`

#### Medium ที่ตัดสินใจข้าม
- **M2** ไม่มี client ไหนส่ง `?category=` (ทุก caller ดึง list เต็มแล้ว filter ฝั่ง browser)
- **M3, M6** เสร็จไปแล้วจาก opencode (verified)

### 🐛 Bonus bug ที่เจอระหว่าง smoke test
- `TokenService::generate()` เคยรับแค่ `(userId, username, role)` → JWT ไม่มี `branch_id` → `$this->user['branch_id']` = null ตลอด → C2 fix ใช้งานไม่ได้
- **แก้:** เพิ่ม `$branchId` param ใน `TokenService::generate()` + `AuthController::login()` ส่ง `$user['branch_id']` ตอน generate

### ⚠️ ผลกระทบที่ user ต้องรู้
**🔴 Token รุ่นเก่าใช้ไม่ได้** — User ที่ login ก่อน 2026-05-28 จะมี JWT ที่ไม่มี `branch_id` field
- อาการ: เปิด sale-lots → ได้ `403 ไม่มีสาขาที่ผูกกับผู้ใช้นี้`
- ทางแก้: **logout แล้ว login ใหม่** (browser localStorage มี token เก่าค้าง)

### 🧪 Smoke test ผ่านครบ 7/7
```
✓ C1   PO ref_no มี branch prefix → PO-B1-... vs PO-B2-...
✓ C2   manager_b1 เห็นเฉพาะสาขา 1 ของตัวเอง
✓ C2.2 bypass ?branch_id=2 ไม่ได้
✓ C3.1 draft บันทึกได้แม้สต็อกไม่พอ (total_cost=0)
✓ C3.2 confirm ปฏิเสธเมื่อสต็อกไม่พอ
✓ C3.3 draft→confirmed สำเร็จเมื่อสต็อกพอ
✓ C3.4 recomputeFifoCost() ทำงาน → total_cost = 24.00 หลัง confirm
```
Script test: `/tmp/smoketest.sh` (ลบเมื่อ session จบ)

### ไฟล์ที่ modified (ยังไม่ commit ณ end of session)
```
.gitignore                                                    (H2)
code/base-pos/api/index.php                                   (H3)
code/base-pos/api/Services/BackupService.php                  (H2)
code/base-pos/api/Services/TokenService.php                   (bonus)
code/base-pos/api/Controllers/AuthController.php              (bonus)
code/base-pos/api/Controllers/InventoryController.php         (M1)
code/base-pos/assets/js/sellers.js                            (H1)
code/customizations/api/Controllers/PriceTiersController.php  (M4)
code/customizations/api/Controllers/SaleLotsController.php    (C2)
code/customizations/api/Models/PurchaseItemCatalog.php        (M5)
code/customizations/api/Models/PurchaseOrder.php              (C1)
code/customizations/api/Models/SaleLot.php                    (C3)
migrations/017→018_rename_tier_labels_to_bill.sql             (H4)
+ uncommitted changes อื่นๆ จาก opencode session ก่อนหน้า (~33 ไฟล์)
```

### Recommended commit plan
```
1. fix: 5 blockers from review (PO ref_no, SaleLot scope, FIFO regression, ID card, mig rename)
2. fix: JWT now carries branch_id (required for sale-lot scope check)
3. chore: medium hardening (CORS env, backup perms, input validation, tier sanitize)
```

### Migrations ที่มีตอนนี้ (014-018 ใหม่)
```
014_add_missing_indexes.sql               # FK + LIKE search columns
015_add_catalog_tier_prices.sql           # price_tier1/2/3 cols
016_add_tier_prices_json.sql              # tier_prices JSON + migrate ข้อมูลเดิม
017_add_expenses_to_sale_lots.sql         # sale_lots.expenses JSON
018_rename_tier_labels_to_bill.sql        # rename labels → "บิล1/2/3" (เคยเป็น 017 ซ้ำ)
```

### ที่ยังเป็น tech debt (ไม่ block production แต่ควรจัดการ)
- Low/Nit จากรีวิว: `UsersController.php:365` indent เพี้ยน, admin/*.html whitespace churn (~79 บรรทัดเหมือนกัน 7 ไฟล์), เมนู "สาขา" ถูกลบจาก sidebar แต่ controller ยังอยู่ (ไม่มีทาง access UI)
- ควรลบ `code/base-pos/backups/.htaccess` (legacy path, BACKUP_DIR ย้ายไป `code/data/backups` แล้ว)
- `JWT_SECRET` ยัง hardcode ใน apache-config.conf (`pos_jwt_secret_key_2024`) → production ต้องเปลี่ยน

---

## 2026-06-01: Inventory SKU & Cost Field Fixes

**Context:** ร้านรับซื้อของเก่า — ราคาทุนมาจาก Purchase Orders ไม่ใช่ตอนเพิ่มสินค้า

**Changes:**
1. ✅ ลบช่อง "ราคาทุน" จากหน้า Inventory (HTML + JS + API validation)
2. ✅ แก้ `category_id` ให้ส่ง `null` แทน `""` เมื่อไม่เลือกหมวดหมู่
3. ✅ แก้ช่อง SKU ให้กรอกเองได้ (แค่ตัวเลข 1, 2, 3... ไม่มี prefix)

**Key Learning:**
- ร้านรับซื้อของเก่า ≠ ร้านค้าทั่วไป → ราคาทุน = ราคารับซื้อจาก PO
- MySQL `INT` column ไม่รับ empty string `""` ต้องเป็น `null`
- ฟังให้ชัดก่อนทำ — "รหัสสินค้าเรียง 1-2-3" ≠ auto-generate (คือกรอกเอง)

**Files:** `inventory.html`, `inventory.js`, `InventoryController.php`

**Detail:** `.learnings/2026-06-01-inventory-sku-cost-fixes.md`

---

## 2026-06-01: Tier Button UX Fix + Category Stock Card

**Context:** Dr.solodev reported two UI issues — (1) price tier buttons not selectable, (2) inventory not reflecting stock after purchase.

**Changes:**
1. **Tier buttons fixed** (`purchase-orders.js`):
   - Always visible from page load (ไม่ต้องรอเลือกสินค้าก่อน)
   - Show actual tier labels + prices from catalog item (e.g. `บิล1 10.00 ฿`)
   - Default labels changed from "ระดับ 1/2/3" → "บิล1/บิล2/บิล3"
   - Select tier first → every item auto-gets that tier price
   - Tier stays selected after adding item to cart
   - Resets properly on clear/input change

2. **Category stock card added** (`inventory.html` + `inventory.js`):
   - New card "สต็อกตามหมวด (กก.)" above products table
   - Shows `stock_kg` for each active category in a grid layout
   - Visual bar graph relative to max stock
   - Color: green (normal), amber (<20%), red (empty)
   - Refreshes on page load + manual refresh button
   - `categories` API already returned `stock_kg` — just needed frontend

3. **HTML/JS**: `escapeHtml()` added to inventory.js (was missing)

**Files:** `purchase-orders.js`, `inventory.html`, `inventory.js`, `AGENTS.md`, `AGENT-MEMORY.md`

---

## 2026-06-05: Global Categories + UX Improvements

**Context:** ลูกค้าเริ่มบันทึก catalog จริง 79 รายการ — พบว่า categories ซ้ำ 4 สาขา + validation ราคาเข้มงวดเกินไป + ต้องการจำสาขาที่เลือก

### Changes

**1. Database: Global Categories Migration**
- **ปัญหา:** categories มี 59 รายการ (4 สาขา × ~15 หมวด) — ชื่อซ้ำกัน
- **แก้:** Merge → 15 หมวดหมู่ global (ลบ branch_id column)
- **Migration 024:** 
  - สร้าง temp mapping: `old_id → canonical_id` (MIN(id) per name)
  - UPDATE FK references ใน `sale_lot_items` (196 rows)
  - DELETE duplicates (เก็บ canonical_id)
  - DROP branch_id + ADD UNIQUE constraint on name
- **Learning:** ต้อง DROP FK constraint ก่อน DROP column

**2. default_unit per Category**
- เพิ่ม column `default_unit VARCHAR(20) DEFAULT 'กก.'`
- "ขวดใส่ลัง" → `default_unit = 'ลัง'`
- Frontend: auto-fill หน่วยตอนเลือกหมวดหมู่ (ใช้ `data-unit` attribute)
- **Migration 025**

**3. localStorage + Branch Banner**
- บันทึกสาขาที่เลือกลง `localStorage.setItem('selected_branch_id', value)`
- โหลดกลับมาตอน init
- แสดง banner: **"🏪 กำลังรับซื้อที่สาขา: XXX"** (สีเหลือง, เด่น)
- **ป้องกัน:** พนักงานลืมเช็คสาขา → บันทึกผิด

**4. Relax Price Validation**
- **ปัญหา:** บังคับ บิล1 **<** บิล2 **<** บิล3 (strictly increasing) แต่บางสินค้าต้องใส่เท่ากัน
- **แก้:** บิล1 **≤** บิล2 **≤** บิล3 (equal allowed, ห้ามกลับด้าน)
- แก้ทั้ง backend (InventoryController.php) และ frontend (inventory.js)

**5. UI Improvements**
- ย้ายปุ่ม "เพิ่มรายการ" + "หมวดหมู่" → card-header ของ "รายการแคตตาล็อก"
- Categories dropdown: แสดง 15 หมวดหมื่อ (ไม่ filter branch)
- ลบ event listener ที่ reload categories ตอนเปลี่ยนสาขา

**6. Data Reset**
- TRUNCATE `purchase_item_catalog` (99 → 0 รายการ)
- เตรียมให้ลูกค้าบันทึกข้อมูลจริง 79 รายการ

### Files Modified
```
code/base-pos/admin/inventory.html                 (UI buttons)
code/base-pos/admin/purchase-orders.html           (branch banner)
code/base-pos/api/Controllers/InventoryController.php  (validation)
code/base-pos/assets/js/inventory.js               (validation)
code/base-pos/assets/js/purchase-orders.js         (localStorage + categories)
code/customizations/migrations/024_merge_duplicate_categories.sql
code/customizations/migrations/025_add_default_unit_to_categories.sql
.learnings/LEARNINGS.md                            (5 new entries)
```

### Key Learnings
- DROP FK constraint ก่อน DROP column (MySQL requirement)
- localStorage + banner = persist + remind (UX pattern)
- Validation: < vs ≤ สำคัญมาก — ถาม user ก่อนเดา
- Global shared data ต้อง merge duplicates + remap FKs อย่างระมัดระวัง

### Status
- ✅ 15 global categories
- ✅ localStorage จำสาขา
- ✅ default_unit auto-fill
- ✅ Validation ผ่อนปรน
- ⏳ รอลูกค้าบันทึก catalog (12/79 รายการ)

---

## 2026-06-01: Final Documentation Update

**Context:** Dr.solodev requested update of all 4 documentation files (AGENTS.md, AGENT-MEMORY.md, root README.md, COMPLETION-REPORT.md) + code/README.md to reflect full project state.

**What was done:**
- **AGENTS.md** — Added missing completed items (code review 5 blockers, Inventory SKU/cost fix, Seller error propagation), migration 017-019 entries, ReportService.php to file map
- **AGENT-MEMORY.md** — Added this session entry
- **root README.md** — Rewrote from research-phase-only to full project overview (business model, architecture, status, quick start)
- **COMPLETION-REPORT.md** — Rewrote from research-phase completion to full project completion covering all 3 phases + P0 hardening + 47 tests passing
- **code/README.md** — Updated migration count (013→021), added Docker setup, test suite, ReportService, responsive CSS

**Key Learning:**
- Root `README.md` was still stuck at research phase (2026-05-16) while all code had moved far ahead
- `COMPLETION-REPORT.md` also only covered research phase — needed full rewrite
- `code/README.md` said 13 migrations but we have 21
- All docs now consistent — AGENTS.md = agent context, AGENT-MEMORY.md = session history, READMEs = user-facing docs

## 2026-06-01: Hardening Round 2 — Throwable catch + Edge-case tests

**Context:** ต่อเนื่องจากการทำ Phase 3 + P0 hardening — เหลือ tech debt เล็กน้อยที่ต้องปิด

### Changes

1. **index.php: `catch (Exception $e)` → `catch (\Throwable $e)`**
   - PHP 8 `catch (Exception $e)` ไม่ catch `TypeError`, `Error` (เช่น method call บน null)
   - เปลี่ยนเป็น `\Throwable` เพื่อ catch PHP errors ทั้งหมด
   - ส่ง `$e->getMessage()` กลับใน response (ไม่ปิดบัง error — dev environment)

2. **Missing env vars in `.env`:**
   - docker-compose.yml ใช้ `${MYSQL_USER}` และ `${MYSQL_PASSWORD}` แต่ .env ไม่มี
   - ทำให้ docker-compose warning ทุกครั้ง + PHP ได้ DB_USER="" → fallback เป็น root
   - เพิ่ม `MYSQL_USER=posuser` และ `MYSQL_PASSWORD=pospass` ทั้ง .env และ .env.example

3. **Edge-case tests (47 → 58 tests, +11 assertions):**
   - `test_sellers.sh`: duplicate phone rejection, blacklist/unblacklist seller workflow
   - `test_purchase_orders.sh`: PO with invalid branch ID rejected
   - `test_sale_lots_fifo.sh`: overstock confirm rejection (999999kg >> available stock), update draft lot (PUT), delete draft lot (DELETE)
   - New helper functions: `api_put()`, `api_delete()`, `assert_not_contains()` in test runner
   - Fix: original overstock test used 9999kg but seed data has 49580kg+ → confirm succeeded unexpectedly → changed to 999999kg

### Key Learnings
- Seed data `categories.stock_kg` มีค่าเริ่มต้นสูงมาก (บางหมวด 190,000+ กก.) → เทสที่พึ่ง "overstock" ต้องใช้ตัวเลขที่เกิน 200,000
- `catch (Exception $e)` vs `catch (\Throwable $e)` — PHP 8 ต้องใช้ Throwable ถ้าอยาก catch ทุกอย่าง
- `api_put`/`api_delete` helpers ใช้ `-X PUT` / `-X DELETE` ใน curl — Router.php match verb เพื่อ dispatch
- docker-compose.yml mapping env vars ต้องตรงกับชื่อใน .env — mismatch ทำให้ warning + fallback values

### Files Modified
- `base-pos/api/index.php` — `catch (Exception $e)` → `catch (\Throwable $e)`
- `.env` — added `MYSQL_USER` + `MYSQL_PASSWORD`
- `.env.example` — same
- `tests/api/test_sellers.sh` — added duplicate phone + blacklist/unblacklist tests
- `tests/api/test_purchase_orders.sh` — added invalid branch test
- `tests/api/test_sale_lots_fifo.sh` — added overstock confirm + update/delete draft lot tests
- `tests/api/helpers/auth.sh` — added `api_put()` + `api_delete()` helpers
- `tests/api/helpers/test_runner.sh` — added `assert_not_contains()` helper
- `AGENTS.md` — updated test count 47→58
- `AGENT-MEMORY.md` — this entry

---

## 2026-06-01: Phase 3 (Reports/Dashboard/Responsive/WeightedAvg) + P0 Hardening

**Context:** รอบนี้เป็น Phase 3 (Reports, Dashboard, Responsive CSS, Weighted Avg Cost) + P0 hardening items (security, cleanup, locking, tests)

### Phase 3 Deliverables
- **4 new report endpoints:** `reports/purchase-report`, `sale-lot-report`, `sale-lot-chart`, `recent-sale-lots`
- **Dashboard:** 3 new stat cards + sale lot chart + recent sale lots table
- **Responsive CSS:** breakpoints at 768px (stack headers) and 576px (smaller tables, stat grid 2 cols)
- **Weighted average cost:** per-branch `cost_method` ENUM('fifo','weighted'), migration 021
- `SaleLot::calculateCost()` now dispatches based on branch setting
- `ReportService.php` — report engine with PO/SL/chart methods

### P0 Hardening Items
- **Security:** JWT_SECRET moved to .env, all Docker credentials to .env (docker-compose.yml), `.env.example` created
- **Console cleanup:** 9 `console.log()` calls removed from 3 JS files (inventory.js, branches.js, common.js)
- **TOCTOU fix:** `SELECT ... FOR UPDATE` added to SaleLot confirm/cancel flow:
  - `updateStatus()` — locks lot row at transaction start (moved SELECT inside transaction)
  - `deductStock()` — FOR UPDATE on PO items + lot row
  - `restoreStock()` — FOR UPDATE on PO items + lot row
  - `recomputeCost()` — FOR UPDATE on items + lot row
- **Bug fix:** `PurchaseOrder::cancel()` called undefined `updateStatus()` — replaced with direct SQL UPDATE
- **Bug fix:** `SaleLot::deductStock()`/`restoreStock()` passed SQL strings to `Database::execute()` which expects PDOStatements — changed to `$this->db->query()` with `rowCount()`
- **Bug fix:** `SaleLot::generateReferenceNo()` didn't include branch code prefix (unlike PO), causing reference_no collisions across branches — added `SO-{BRANCH_CODE}-YYYYMMDD-NNN` format

### Test Suite Expansion
- **33 → 47 tests** (14 new assertions)
- New `test_sale_lots_fifo.sh`: Full confirm/cancel FIFO flow (13 assertions)
  - Creates PO → creates sale lot → confirms → verifies status → cancels → verifies → reject re-confirm
- Updated `test_purchase_orders.sh`: Added PO cancel test
- New `api_post_id` helper in `helpers/auth.sh` for POST with `?id=` query params
- All 47 tests pass

### Files Modified
- `customizations/api/Models/SaleLot.php` — FOR UPDATE locking, deductStock/restoreStock bug fix, reference_no format fix
- `customizations/api/Models/PurchaseOrder.php` — cancel() undefined method fix
- `customizations/api/Controllers/SaleLotsController.php` — (debug revert)
- `customizations/api/Controllers/PurchaseOrdersController.php` — (no changes needed)
- `base-pos/assets/js/inventory.js` — removed 2 console.log()
- `base-pos/assets/js/branches.js` — removed 5 console.log()
- `base-pos/assets/js/common.js` — removed 2 console.log()
- `docker-compose.yml` — all credentials use `${VAR}` references
- `.env.example` — updated with all Docker credentials
- `base-pos/api/index.php` — (debug revert)
- `tests/api/test_sale_lots_fifo.sh` — NEW file (FIFO flow test)
- `tests/api/test_purchase_orders.sh` — added PO cancel test
- `tests/api/helpers/auth.sh` — added `api_post_id()` helper
- `AGENTS.md` — updated (migrations 021, new endpoints, bug fixes)
- `AGENT-MEMORY.md` — updated (this entry)
- `code/README.md` — updated (migrations, setup, tests)

### Key Technical Learnings
1. `Database::execute()` expects a PDOStatement, not SQL string! Use `query()` for raw SQL
2. `pdo->lastInsertId()` returns a string → JSON encodes as `"id":"123"` not `"id":123`
3. PHP 8 `catch (Exception $e)` does NOT catch `TypeError` or `Error` — use `catch (\Throwable $e)` for both
4. `config.php` has an `APP_ACCESS` guard — must be defined before require
5. Sale lot confirm/cancel was never tested in the original test suite (33 tests but all skipped confirm/cancel)
6. `FOR UPDATE` requires being inside a transaction — can't lock rows outside `beginTransaction()`

---
