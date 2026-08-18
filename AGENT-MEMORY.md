# 🤖 Agent Memory — Scrap POS
**Last updated:** 18 สิงหาคม 2569 (WF-06 v2.1 ตาม Owner decisions — implement เสร็จ + ทดสอบผ่าน 70/70 + UI QA ผ่าน — **ยังไม่ commit**)
**Status:** ทำงานตาม **`docs/WORK-PLAN-2026-08-18.md`** (อ่านก่อนทำงานทุกครั้ง — mission control) — main อยู่ที่ `9bbed77` (ก่อน v2.1); working tree มี v2.1 ทั้งชุด (CashSession/PO/Expense/SaleLot/CashDepositRequest/migration 071/UI/tests) — **ยังไม่ได้ commit + push + deploy** — production ร้าน untouched `81d2306`
**งานค้างที่สำคัญ:** (1) commit + push v2.1 → รายงาน Owner → รออนุมัติ **clean start** (ลบตัวเลขเก่า baseline 0) → backup → deploy (ชุด A/B ที่ยังไม่ deploy ต้องรวมด้วย) (2) cash sandbox 8082 ใช้ compose `-p cash-sandbox` — อย่า reset ซ้ำซาก ตัวเลข test อยู่ใน sandbox นี้ (3) sandbox หลัก `scrap-pos-ui-*` (8081) ยัง Exited — ฟื้นเมื่อทำงาน print server QA (Track A รอ Owner เรียก)

---

## 🎯 Deal & Context

- **ราคา:** 40,000 บาท | ปิดดีล 18 พ.ค. 2569
- **ลูกค้า:** ร้านรับซื้อของเก่า 4 สาขา จ.สุรินทร์
- **⚠️ ลูกค้าโดนทิ้งงาน 2 ครั้ง** → trust สำคัญที่สุด อัปเดต progress ทุกสัปดาห์
- **Mindset:** ขายผลงาน ไม่ได้ขายวิญญาณ — อย่าอาสาลดราคาเอง

---

## ✅ GOALS (G1-G11)

| Goal | สถานะ | รายละเอียด | ตรวจสอบโค้ดจริง |
|:-----|:-----:|:------------|:-----------------|
| G1 | ✅✅ | ถ่ายรูปสินค้า — `PhotoUploadController` (255 lines, resize, HMAC auth), `photo-upload.html`+`js` (mobile standalone), camera/gallery/FAB/QR handoff ใน `purchase-orders.js`, ID card photo ใน `sellers.js` | ใช้ dev compose → `code/uploads` ไปก่อน (G1 Host Directory เลื่อนทำหลัง deploy — 08 ส.ค.) |
| G2 | ✅ | ใบรับซื้อ 2 แบบ (ปกติ + โลหะมีค่า auto-detect, บังคับเซ็นรับรอง) | `PurchaseOrdersController:152,212`, `purchase-orders.js:627` |
| G3 | ✅ | Blacklist alert popup สีแดง + blacklist_reason | `purchase-orders.js:972`, `sellers.js:44`, routes `sellers/blacklist`, `sellers/unblacklist` |
| G4 | ✅ | ค้นหาผู้ขาย real-time debounce 300ms + blacklist badge | `purchase-orders.js:933`, `sellers.js:75` |
| G5 | ✅ | Dashboard 4 สาขา: stats-grid 6 cards, charts, branch filter, recent PO table | `admin/index.html`, `dashboard.js` |
| G6 | ✅ | บอร์ดราคาพิมพ์ได้ | `admin/price-board.html` |
| G7 | ✅ | ประวัติผู้ขาย — Data Center modal + seller-history.html (ID card photo, item thumbnails, PO gallery, lightbox) | `sellers.js:157`, `seller-history.html` |
| G8 | ✅ | Export CSV 7 แบบ (sales, products, inventory, cashier, tax, purchase, salelot) + UTF-8 BOM | `reports.js:1075-1278` |
| G9 | ✅ | โอนสต็อกระหว่างสาขา — FIFO consumed_qty, weighted avg cost, transfer PO, atomic transaction FOR UPDATE, reference_no ST-YYYYMMDD-NNN | `StockTransfersController.php`, `StockTransfer.php` |
| G10 | ✅ | Stock alert — threshold ต่อหมวด, low stock count ใน dashboard | `InventoryController.php:484`, `settings.js:125` |
| G11 | ✅ | Mobile/Tablet — Plan A (responsive breakpoint 768-1024px, priority-2/3 columns, hamburger) + Plan B (mobile PO wizard 4 steps) | `layout.css`, `mobile/purchase.html` |

---

## ⏳ Todo

### 🚀 15 ส.ค. 2569 — UI Refresh V2: Header 48px + Carousel (Phase: UI Refresh)
**จุดประสงค์:** รวม Top Bar + Page Header เป็นแถวเดียว (48px) + หน้าข้อมูลยาวใช้ Carousel — ทดสอบใน sandbox แยก ไม่แตะ production ที่ร้าน

- [x] **Sync กับ production** — pull 5 commits (`bbedf1a`..`4119b6d`), local = prod `4119b6d`, tag `prod-2026-08-15`, commit `caa1e34` (AGENT-MEMORY + SHOP-DEPLOY-RUNBOOK)
- [x] **Sandbox แยก** — branch `feat/ui-refresh-v2` + `docker-compose.ui-sandbox.yml` (project `ui-sandbox`, ports 8081/3308/8444, volume `ui_db_data`, uploads `uploads-ui/`) — container `scrap-pos-ui-web`/`scrap-pos-ui-db` — login admin/admin
- [x] **Fix docker bugs จาก 4119b6d** — `Dockerfile.php` COPY path ผิด (build context ./docker) + `docker/ssl/` ว่าง (mount :ro) → สร้าง cert + `entrypoint.sh` fallback สร้าง cert อัตโนมัติ
- [x] **Test suite บน sandbox: 404/405** (1 fail = `AUTH-52b backup:false` — pre-existing, super_manager = admin TEST-MODE ชั่วคราว — test ยังไม่ update ตาม Access Control v3)
- [x] **Header merge 18 หน้า** — `19b8f97`: `topbar > [page-header(flex:1) + user-dropdown]`, `--header-height` 60→48px, h1 32→20px, seller-history เพิ่ม h1 "ประวัติผู้ขาย", stock-transfers รวม transfer-page-header, ลบ `.po-page-header` CSS, purchase-orders ชื่อร้านชิดซ้าย
- [x] **Carousel 2 หน้า** — `2f2b507`: `carousel.js` (scroll-snap, prev/next, dots, keyboard, print expand) + `layout.css` — financial-summary 6 slides, reports 5 slides
- [x] **QA CDP (Chrome headless 151)** — `87a6afd`: header 49px desktop / 53px mobile วัดจริง, ROW ติดทุกหน้า 19 หน้า, overflow none, console errors none, carousel scroll 0→1142px + dots active — screenshots หลักฐาน `/tmp/opencode/shots/final/`
- [x] **QA พบ fix** — reports-filter/cash-page-tools ออกจาก topbar (header สูง 81/79px), avatar 32→28px + padding จัดให้ได้ 49px จริง
- [x] **Owner ตรวจ visual** (ผ่าน + เปลี่ยนเป็น tabs) — screenshots `/tmp/opencode/shots/final/` (8 หน้า) — รอ approve ก่อน merge main
- [x] **Merge `feat/ui-refresh-v2` → main** (`a0911ab` + tag `prod-2026-08-15-v2`) — หลัง approve: merge + push + ลูกค้าตรวจบน sandbox link
- [x] **Deploy UI ไปร้าน** (15 ส.ค. 66e9c2c→a0911ab, backup ก่อน deploy, ข้อมูลจริงเท่าเดิม, Cloudflare purge + cache-busting) — หลังลูกค้า approve: ตาม SHOP-DEPLOY-RUNBOOK (pull + restart web container ที่ร้าน)

### 🚀 08 ส.ค. 2569 — Deploy Prep: Fresh Start 2 สาขา (Phase: Deploy)
**จุดประสงค์:** deploy ใช้จริงครั้งแรก แค่ 2 สาขา + ล้างข้อมูลทดสอบหมด (เริ่มนับ 1 ใหม่) — ลูกค้าตั้งชื่อสาขาใหม่ตั้งแต่ต้นผ่าน UI

- [x] **ซ่อม migration permission** — 037/038 มี mode 600 → container อ่านไม่ได้ → migration ค้างที่ 036 → login 500 (AuthController ใช้ login_attempts) — chmod 644 + เพิ่ม pre-flight check ใน `run-migrations.sh` (กันเกิดซ้ำ)
- [x] **Fix บั๊ก RC1** — `006p_seed_demo_data.sql` ไม่เคยถูกรัน (runner ตัด suffix → ซ้ำกับ 006) → แก้ให้ version มี suffix รันจริง — test suite/CI อิง demo seed
- [x] **Fix บั๊ก RC2 (block production)** — `sellers.vehicle_type` ที่ model/controller/UI ใช้แต่ migration ไม่เคยสร้าง → seller API 500 ทุก endpoint → สร้าง `070_add_vehicle_type_to_sellers.sql`
- [x] **Fix บั๊ก RC3** — `tests/api/helpers/auth.sh` TEST_PASS default ผิด (`password` → `admin`)
- [x] **Test Suite 405/405 ผ่าน** (ก่อนแก้: 110/96) — phpunit ยังไม่ได้ติดตั้ง (ไม่มี vendor/)
- [x] **`reset-data.sql`** — standalone (ห้ามใส่ migrations/!) — TRUNCATE 34 ตาราง + reset ID=1 + branches เหลือ BR01/BR02 + ลบ demo users + ล้างข้อมูลปลอม 006p — รันหลัง init DB ใหม่เท่านั้น (local + production)
- [x] **DB pristine** — migrations 070/70, users=1 (admin/admin), branches=2 (ยังไม่ได้ตั้งชื่อ), categories/catalog/sellers/PO/sale_lots=0, settings/item_conditions เก็บไว้, uploads=0
- [x] **Commit + push** — `9d39242` (fix 3 บั๊ก + reset-data.sql + OWNER-FIRST-USE.md), `b071b12` (SHOP-DEPLOY-RUNBOOK.md + BRANCH-INFO-FORM.md)
- [x] **แพ็กเกจไปร้าน** — `/tmp/opencode/pos-deploy-package/pos-deploy-package.zip` (.env.production พร้อมรหัสสุ่ม + runbook 8 STEP + ฟอร์มสาขา)
- [ ] **Owner ไปร้าน (รอทำ)** — runbook STEP 1-8: Docker + Tailscale + SSH + git clone + `.env` + `compose up` + รอ migration + รัน reset-data.sql + ทดสอบ Windows 10 login
- [ ] **Deploy จริงจากบ้าน (ผมอัปเดตต่อ)** — ตรวจผ่าน Tailscale SSH + เปลี่ยน admin password + remote access
- [ ] **ตัดสินใจ domain** — ยังไม่มี → ใช้ Tailscale ก่อน (ร้าน LAN + owner มือถือ); ถ้าลูกค้าอยากได้ URL งามๆ ค่อยซื้อ .com (~300฿/ปี) + Cloudflare Tunnel

### 🎉 04 ส.ค. 2569 — Client Acceptance + Deployment (Phase: Stabilize)
- [x] **ลูกค้าตรวจรับงานผ่าน** ✅ (ลูกค้าโดนทิ้งงาน 2 ครั้ง → trust สำคัญ — ผ่านด่านนี้แล้ว)
- [x] **ติดตั้ง Ubuntu Server ที่ร้านลูกค้า** ✅ — Owner ลงมือเอง เสร็จ 04 ส.ค.
- [ ] **Deploy แอปขึ้น server ร้าน** — docker compose ตัว dev (`docker-compose.yml`) — upload path `code/uploads` (ใช้ dev compose ไปก่อน; G1 Host Directory เลื่อนทำหลัง deploy)
- [ ] **Cloudflare Tunnel** — remote access dashboard จากมือถือ (ยังค้างจากก่อนนำเสนอ)
- [ ] **NAS/Storage setup จริง** — ⏸️ เลื่อนทำหลัง deploy — ระหว่างนี้ใช้ dev compose → `code/uploads` ไปก่อน
- [ ] **QA photo capture** — ทดสอบเต็มรูปแบบหลัง deploy จริง
- [ ] **ปรับให้สมบูรณ์ + เสถียร** — รับ feedback การใช้งานจริงจากพนักงานหน้าร้าน, monitor logs

### ด่วน — ก่อนนำเสนอลูกค้า (ประวัติ — จบแล้ว)
- [x] **นำเสนอลูกค้า (ผู้ว่าจ้าง)** — เปิด `http://localhost:8080/admin/index.html` (desktop) + `http://localhost:8080/mobile/purchase.html` (tablet) ✅ ตรวจผ่าน
- [x] G1: choose storage ✅ — **Owner เลือก Host Directory** — ⏸️ เลื่อนทำหลัง deploy (08 ส.ค.) → ใช้ dev compose `code/uploads` ไปก่อน
- [ ] เปิด Docker + Cloudflare Tunnel ก่อนนำเสนอ — remote access dashboard จากมือถือ (ย้ายไป Todo ข้างบน)

### ✅ Tech Debt — Fixed (verified)
- [x] ~~JWT_SECRET ย้ายออกจาก apache-config.conf ก่อน production~~ → ย้ายเข้า .env แล้ว
- [x] ~~Rate limiting~~ — DB-based (login_attempts table)
- [x] ~~Token revocation~~ — token_blocklist + jti
- [x] ~~requireAuth()~~ — เพิ่มในทุก controllers ที่ขาด
- [x] ~~Production Audit (54 issues)~~ — 27 fixed, 90% readiness
- [x] ~~CSS cleanup~~ — legacy fonts removed, duplicate @media merged
- [x] ~~seller-history.html~~ — เพิ่มรูป ID card + item thumbnails + lightbox
- [x] ~~Dashboard branch filter~~ — filter ส่งต่อไปทุก table API
- [x] ~~CSV UTF-8 BOM~~ — เพิ่ม \uFEFF ใน client-side exports
- [x] ~~Financial summary~~ — error notifications แทน infinite loading

### ✅ Tech Debt — Resolved (2026-07-26)
- [x] **รัน migration:** 046, 047, 051, 052, 053 — ครบทั้งหมด 001-053 ✅
- [x] **Sidebar links:** stock-transfers + price-board มีครบทุกหน้า 18 หน้า (ตรวจสอบแล้ว)
- [x] **ลบ legacy:** `base-pos/backups/.htaccess` + `database/security-migrations.sql` (ซ้ำซ้อน)
- [x] **เมนูสาขา:** branches.html มีใน sidebar ทุกหน้าอยู่แล้ว
- [x] **Test default password:** auth.sh อัปเดตเป็น `password` (ตรงกับ DB)

## 🗑️ Dead Code — อย่าแตะ อย่า restore (ตัดสินใจแล้ว 14 มิ.ย. 2569)

ร้านของเก่าใช้ **Sale Lot เป็นช่องทางขายหลัก** (ขายหน้าร้านนานๆครั้ง) จึงตัด retail POS ออก:

| ไฟล์/ส่วน | สถานะ | หมายเหตุ |
|---|---|---|
| `admin/sales.html` | dead — ไม่ได้ link ใน sidebar | หน้า POS terminal เดิม (base-pos) |
| `api/Controllers/SalesController.php` | dead — routes ถูกลบออกจาก Router แล้ว | requireAuth() ยังอยู่ แต่ไม่มีใครเรียก |
| `api/Models/Sale.php` | ⚠️ ยังเก็บไว้ | `CustomersController` ยังใช้ Sale model |
| Router `sales/*` routes (5 เส้น) | ลบออกแล้ว | createSale, getSales, getSaleDetails, voidSale, exportSales |

**อย่า** เพิ่ม route sales/* กลับเข้าไปใน Router.php โดยไม่ได้ตัดสินใจก่อน

### UX Task — Photo Capture Feature 🚀 (Implementation done)
- [x] ✅ UX Requirements spec compiled → `docs/UX-REQUIREMENTS-PHOTO-CAPTURE.md`
- [x] ✅ Architect Review (พี่ทรงศักดิ์) — อนุมัติ
- [x] ✅ UI/UX — Desktop (webcam + file picker + FAB + photo strip + QR) + Mobile standalone page
- [x] ✅ Engineering — PhotoUploadController (255 lines) + photo-upload.html/js + ID card photo in sellers.js
- [ ] 🧪 QA — Testing (เริ่มได้หลัง NAS setup + deploy) | **Owner เลือก NAS แล้ว — รอ setup จริง**

### Phase ถัดไป
- [ ] Reports filter by branch
- [ ] รายงาน: กำไรต่อชิ้น, สินค้าค้างนาน, เปรียบเทียบสาขา, Top 10 ผู้ขาย
- [ ] Hybrid Online/Offline (Phase 4)

---

## 🚀 WF-05: Purchase Flow UX — Client Feedback (2026-07-13)

### Overview
ลูกค้าต้องการ flow รับซื้อที่ **เร็วขึ้น — keyboard-first, minimal clicks** หลังดู Demo

| Requirement | การแก้ไข | Status |
|:------------|:---------|:------:|
| **Layout** — ผู้ขายอยู่บนสุด, ตามด้วยบิล, สาขา, +ผู้ขายใหม่ | ย้าย DOM order ใน `purchase-orders.html` | ✅ |
| **บิล1 default** — ไม่ต้องกดเลือกทุกครั้ง | `globalTier.level = null` → `1` | ✅ |
| **Auto-select** — พิมพ์รหัสแล้วผลลัพธ์เดียว → เลือกให้อัตโนมัติ | `searchCatalogImmediate()` + exact match check | ✅ |
| **Select-all on focus** — focus ช่องน้ำหนัก → เลือกข้อความทั้งหมดไม่ต้องลบ | `this.select()` ใน `focus` event | ✅ |
| **Focus ring** — mouse กับ keyboard แยกกัน | `:focus` (box-shadow) / `:focus-visible` (outline) | ✅ |
| **สัดส่วน bottom row** — 0.8fr / 1fr / 1.2fr | ปรับ flex ratios | ✅ |

### ⚠️ Key Design Decision
- **Penpot integration ถูกเลื่อน (on hold)** — ครีเอทตัดสินใจไม่เอา layer 0-5 template ไปใช้ต่อในรอบนี้
- **ต้อง deploy 3 ไฟล์:** `purchase-orders.js`, `purchase-orders.html`, `purchase-orders.css` + 1 ไฟล์ shared `forms.css`

### คณะทำงาน
- **Design Director:** `@design-kreet` (ครีเอท) — ตรวจสอบ + approve ✅
- **UI Designer:** `@ui-designer` (ยูไอดี) — ทำ audit pixel
- **Engineering:** `@changful` (ช่างฟูล) — review overlay feasibility
- **Client:** ผู้ว่าจ้างร้านรับซื้อของเก่า 4 สาขา จ.สุรินทร์

### Reference
ดู spec เต็มได้ที่: `code/docs/workflows/WORKFLOW-05-purchase-flow-ux.md`

---

## 📁 Schema — Migrations (50 migrations, verifed on disk)

```
001      core: branches
002      sellers
003      item_conditions (mojibake fixed)
004      purchase_orders + purchase_photos
005      seed_categories
006      three_tier_pricing + demo_data
007      price_tiers
008      tier to purchase_items
009      sale_lots
010      consumed_qty (FIFO foundation)
011      seller_vehicle_plate
012      purchase_item_catalog
013      weight_deduction (replaces condition)
014      missing indexes
015-016  catalog tier prices (json)
017      expenses to sale_lots
018      rename tier labels → บิล1/2/3
019      stock_kg to categories
020      FIFO indexes + atomic consumed
021      cost_method to branches
022      branch_id to categories
023      transfer_logistics (ARCHIVED → replaced by 025)
024      stock_transfers
025      transfer_logistics_fields
026      populate_catalog_categories
027      merge_duplicate_categories
028      default_unit to categories
029      actual_revenue to sale_lots
030      business_expenses
031      blacklist_fields
032      precious_receipt_flag
033      stock_alert_threshold
034      catalog_id to sale_lot_items
035      updated_by to sale_lots
036      po_id to purchase_order_photos
037      login_attempts
038      token_blocklist
039      transport_cost to sale_lots
040      employees_table
041      idempotency_keys
042      requires_id_card to catalog
043      blacklisted_by to sellers
044      fix po_photos FK RESTRICT
045      pdpa_consent to sellers
046      enforce_precious_receipt on all risk categories
047      seller_id_card_search_index
048      sale_lots_created_at_index
049      set_default_catalog_prices
050      repair_mojibake_text
```

---

## 🚀 Commands

```bash
cd /home/drsolodev/projects/scrap-pos/code

docker compose up -d
docker compose logs -f web
docker compose restart web
docker compose down -v && docker compose up -d --build

# MySQL CLI
docker exec -it scrap-pos-db mysql -uroot -prootpass pos_system
# ⚠️ ภาษาไทยต้องใส่ --default-character-set=utf8mb4
```

**URLs:** Admin `http://localhost:8080/admin/` (admin/admin123) | PMA `http://localhost:8081/` (root/rootpass)

---

## ⚠️ ข้อควรระวัง

- **ห้ามแก้ base-pos/ โดยตรง** ยกเว้นจำเป็น + comment เหตุผล
- Migration รันครั้งเดียว — แก้เก่าต้อง `docker compose down -v`
- production: เปลี่ยน JWT_SECRET ใน .env ก่อน deploy
- token key ในระบบ: `posToken` / `posUser` (ไม่ใช่ `token`/`user`)

---

## 🤝 Dr.Solodev Style

- ฟังก่อน ไม่รีบ jump to solution
- บอกตรงๆ ได้ ไม่ต้องกลัวเถียง
- ทำตรงตามที่สั่ง ไม่บวกขอบเขตเอง
- Solo dev — ไม่มีทีม ไม่มี safety net

---

*Session logs ย้อนหลัง → `AGENT-HISTORY.md`*

---

## 🏆 FIFO Architecture Review — Owner Feedback (2026-07-12)

### Components Reviewed
| Component | Lines | Path |
|:----------|:-----:|:-----|
| Router (Switch Controller) | 281 | `base-pos/api/Router.php` |
| SaleLotsController | 309 | `customizations/api/Controllers/SaleLotsController.php` |
| SaleLot Model (FIFO Engine) | 734 | `customizations/api/Models/SaleLot.php` |

### Owner Score: **9/10**
> *"คุณคิดแบบ business process ของร้านรับซื้อของเก่าจริงๆ ไม่ได้แค่ดัดแปลง POS ทั่วไป"*

### v2 Roadmap (Owner Approved)
1. **State transition business rules** — formal state machine for PO/Sale Lot lifecycle
2. **Deadlock lock order refactor** — consistent LOCK TABLE order across all transactions
3. **FIFO mapping per sale item** — track which `purchase_order_item_id` each sale_lot_item consumed
4. **Reconcile script** — `stock_kg` vs `SUM(poi.qty - consumed_qty)` audit

---

## 🔬 Competitive Analysis (2026-07-12)

| Competitor | ร้าน | จุดเด่น | จุดอ่อนเราเทียบ |
|:-----------|:---:|:--------|:----------------|
| **POSPOS** | ~1,000+ | Scale, CCTV, ID card, BOM | Cloud SaaS รายเดือน |
| **Scrapee** | ~70 | ATM Machine 420K, 59,900+2,990/เดือน | แพง, offline |
| **Green2Get** | ~300+ | ฟรีตลอดชีพ → 290-2,990/เดือน | Offline mode, QC |
| **ScaleBuy** | ใหม่ | Mobile-first, ฟรี 14 วัน | Mobile-first |
| **Recyclebiz** | ไม่ชัดเจน | — | — |

### Our Differentiators
- ✅ **Sale Lot + Profit/Loss ต่อ Lot** — ไม่มีคู่แข่งมี
- ✅ **Multi-branch + Stock Transfer** — ไม่มีคู่แข่งมี
- ✅ **FIFO/Weighted Avg Costing** — ไม่มีคู่แข่งมี
- ✅ **Self-hosted (Docker) ฟรีตลอดชีพ**
- ✅ **Enterprise-grade Security** — CSP, requireAuth, Transaction, FOR UPDATE

### Gaps to Close
- 🟥 **CRITICAL:** Scale integration (digital weighing scale) — ทั้ง POSPOS, Scrapee, Green2Get มี
- 🟧 **HIGH:** Mobile/Tablet support — **PLAN A+B IMPLEMENTED THEN** ✅
- 🟧 **HIGH:** Offline Mode (Green2Get มี)

---

## 🌙 Night Audit 2026-06-08→09 (Claude Code, end-to-end test)

### ✅ Flow ที่เทสแล้วทำงานครบ (API level)
- **Auth** — admin/admin123, staff1/password, token protection 401 ✅
- **รับซื้อ (PO)** — สร้าง PO → `categories.stock_kg` เพิ่มตาม net qty ✅ (ต้องส่ง category_id)
- **ขาย Lot** — draft → confirm (ตัด stock_kg + consumed_qty, FIFO cost) → cancel (คืนครบ) ✅

### 🐛 Bugs ที่แก้แล้วคืนนี้
1. **item_conditions mojibake** — ดี/พอใช้/ชำรุด double-encoded. แก้ด้วย UPDATE + เพิ่ม `SET NAMES utf8mb4` ใน migration 003 + `--default-character-set` ใน run-migrations.sh
2. **StockTransfersController requireAuth bug** — `$user = $this->requireAuth()` คืน `true` (bool) ไม่ใช่ user object → `$user['role']`/`$user['id']` = null → โอนสต็อกไม่ได้ทุก role + created_by NULL. แก้เป็น `$this->user` ใน store/confirm

### ⚠️ ARCHITECTURAL ISSUE — ต้องคุย Dr.solodev (อย่าแก้คนเดียว)
- **stock_kg เป็น global** (categories ไม่มี branch_id) แต่สต็อกจริงต่อสาขาอยู่ที่ purchase_order_items+po.branch_id
- stock transfer confirm() หัก stock_kg อย่างเดียว ไม่เพิ่มปลายทาง ไม่ย้าย PO items → "โอนสาขา" ไม่ย้ายของจริงระหว่างสาขา
- กระทบ dashboard/reports/financial ถ้าแก้ → ต้อง decision: categories per-branch หรือ stock_kg เลิกใช้ คิดจาก PO items อย่างเดียว

### ⏳ ยังไม่เทส
- Dashboard 4 สาขา, Reports filter, Financial summary, Export CSV
- หน้า /pos/index.html (cashier เดิม base-pos) ล้นขึ้นบน — ผิดธุรกิจ ควรซ่อน/redirect

### 📊 Dashboard/Reports/Financial — เทสแล้วทำงานครบ ✅
- ทุก endpoint success, ข้อมูลมีค่าจริง, CSV ไทยถูก (มี BOM)
- branch summary 4 สาขา, financial summary (ซื้อ 67,439 / ขาย 9,500) ✅

### 🚨 DEMO BLOCKER — Dashboard โชว์ "กำไรเดือนนี้ -844,918"
- สาเหตุ: test data ขยะ — sale_lot 78 (BR01) น้ำหนัก 9,999 กก. cost 1.1M
- garbage = 3 sale_lot_items (qty 9999) + 1 confirmed lot → loss -860,190
- ถ้าตัดออก: กำไรจริง = +363,216 (สมจริง)
- **backup แล้ว:** backups/pre-demo-20260608-2345.sql (773K)
- ⚠️ การลบเป็น decision Dr.solodev — เตรียม SQL ไว้ ยังไม่ลบ
- SQL: DELETE FROM sale_lots WHERE id=78; (+ cascade items) — ดู lot ที่ total_cost>total_amount*2

### 🛒 หน้า /pos/index.html ที่ "ล้นขึ้นบน" — ROOT CAUSE
- เป็นหน้า **ขายปลีกหน้าร้าน ของ base-pos เดิม** (title: "จุดขายหน้าร้าน")
- ไม่เข้ากับธุรกิจ — ร้านนี้ขายเป็น Lot ให้โรงงาน ไม่ขายปลีก
- มี 2 เมนู legacy ที่ควรซ่อน: "ขายหน้าร้าน" (15 หน้า) + "ประวัติการขาย" sales.html (14 หน้า)
- sales.js ยังเรียก sales API เดิม (ขายปลีก) — ไม่ใช่ sale-lots
- **เตรียม script แล้ว:** code/docker/hide-legacy-retail-menus.sh (ยังไม่รัน — scope decision)
- ⚠️ ถาม Dr.solodev: ซ่อนถาวร / redirect ไป sale-lots / ปล่อยไว้?

### ✅ Final Night Fixes After Dr.solodev Approval (2026-06-09 ~03:00)
- **ลบ demo garbage data แล้ว**: ลบ sale_lots ที่ cost > amount*2 จำนวน 30 lots + คืน stock จาก lot 78; dashboard profit กลับเป็นบวก (`month_salelot_profit` ~15,478)
- **หน้า /pos/index.html**: เปลี่ยนเป็น redirect ไป `admin/sale-lots.html`; sidebar "ขายหน้าร้าน"/"ประวัติการขาย" ชี้ไป Sale Lots (ขายจริงของธุรกิจ) แล้ว
- **Stock transfer architecture แก้แล้วและเทสผ่าน**:
  - confirm transfer หัก FIFO `purchase_order_items.consumed_qty` จากสาขาต้นทาง
  - สร้าง transfer PO ในสาขาปลายทางโดยใช้ weighted avg cost ของของที่โอน
  - `categories.stock_kg` global ไม่ถูกลด เพราะของยังอยู่ในระบบ แค่ย้ายสาขา
   - E2E test: BR2→BR1 50kg แล้วขายจาก BR1 30kg สำเร็จ; stock ต่อสาขาอัปเดตถูก

---

## 🧠 Session 2026-07-26 — รูป Item Photo ไม่ผูกกับ item_id (Fix Complete)

### ปัญหา
- เวลาถ่ายรูปสินค้าตอนทำ PO รูปถูก upload ไปที่ server แต่ `purchase_order_item_id = NULL`
- สาเหตุ: `createWithItems()` ไม่ return item IDs → frontend ไม่มีทางส่ง `item_id` ไปกับ photo
- seller-history.html จึงไม่แสดง item photos thumbnails (ดูได้แค่รูปบัตรประชาชน)

### แก้ไข (3 ไฟล์)
| ไฟล์ | แก้ไข |
|:-----|:------|
| `customizations/api/Models/PurchaseOrder.php` | `createWithItems()` เก็บ `$itemMappings[]` {id, client_key} หลัง insert แต่ละ item, return `items` array |
| `customizations/api/Controllers/PurchaseOrdersController.php` | รับ `client_key` จาก items payload |
| `base-pos/assets/js/purchase-orders.js` | ส่ง `client_key: it._tempId`, สร้าง `tempIdToItemId` map, upload รูปพร้อม `item_id` |

### Verify
- PHP syntax 23/23 ✅
- API test: PO + photo upload + DB check ✅
- Data Center API ส่ง item photos ครบ ✅
- Edge cases: upload ไม่มี item_id (fallback) ได้ ✅
- Full test suite: 78/88 pass (failures = pre-existing FIFO/Sale Lot tests ไม่เกี่ยวกับ photo) ✅

### ข้อสรุป
- รูปสินค้าเก็บไว้ดูในระบบประวัติเท่านั้น (seller-history.html, PO detail)
- **ไม่มีรูปสินค้าออกไปที่บิลใบรับซื้อ** — print-receipt.html แสดงแค่ชื่อ + น้ำหนัก + ราคา
- commit: `ad5f1f0` + unstaged changes

---

## 🧠 Session 2026-07-26 — Tech Debt Cleanup (CEO เทอโบ)

### งานที่ทำ
1. ✅ **Commit staged changes** — 9 ไฟล์, 686 บรรทัด (thermal receipt + item_id photo binding) — `4faf9b8`
2. ✅ **ลบ legacy files** — `backups/.htaccess` + `security-migrations.sql` (ซ้ำซ้อน) — `312d923`
3. ✅ **รัน migrations ที่ค้าง** — 046, 047, 051, 052, 053 — ครบ 001-053
   - 051 `branch_stock` table: 17 rows populated (ใช้ INSERT IGNORE กันข้อมูลซ้ำ)
4. ✅ **Sidebar audit** — ทุก 18 หน้ามี stock-transfers + price-board + branches ครบ
5. ✅ **Tests** — 78/88 pass (10 failures = pre-existing FIFO/Sale Lot issues)
6. ✅ **auth.sh default password** — แก้เป็น `password` (ตรงกับ DB)

### สถานะล่าสุด
- **Migrations:** 001-053 complete ✅
- **Tech Debt:** ทั้งหมดที่ค้างไว้เคลียร์แล้ว ✅
- **Working tree:** clean
- **next:** Phase Reports filter by branch / กำไรต่อชิ้น / Hybrid Offline
