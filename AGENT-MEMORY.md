# 🤖 Agent Memory — Scrap POS
**Last updated:** 12 กรกฎาคม 2569
**Status:** GOALS G1-G10 เสร็จครบ + G11 (Mobile/Tablet) ✅ | Production Audit ✅ | FIFO Code Review — Architecture 9/10 ✅

---

## 🎯 Deal & Context

- **ราคา:** 40,000 บาท | ปิดดีล 18 พ.ค. 2569
- **ลูกค้า:** ร้านรับซื้อของเก่า 4 สาขา จ.สุรินทร์
- **⚠️ ลูกค้าโดนทิ้งงาน 2 ครั้ง** → trust สำคัญที่สุด อัปเดต progress ทุกสัปดาห์
- **Mindset:** ขายผลงาน ไม่ได้ขายวิญญาณ — อย่าอาสาลดราคาเอง

---

## ✅ GOALS (G1-G11)

| Goal | สถานะ | รายละเอียด |
|---|---|---|
| G1 | ⏳ | ถ่ายรูปสินค้า — รอเจ้าของเลือก storage |
| G2 | ✅ | ใบรับซื้อพิมพ์ได้ 2 แบบ (ปกติ + โลหะมีค่า auto-detect) |
| G3 | ✅ | Blacklist alert popup สีแดง + blacklist_reason |
| G4 | ✅ | ค้นหาผู้ขาย real-time + blacklist badge |
| G5 | ✅ | Dashboard 4 สาขา: รวม / เลือกสาขา / side-by-side |
| G6 | ✅ | บอร์ดราคาพิมพ์ได้ (price-board.html) |
| G7 | ✅ | ประวัติผู้ขายต่อคน (modal) |
| G8 | ✅ | Export CSV 4 แบบ |
| G9 | ✅ | โอนสต็อกระหว่างสาขา + audit trail |
| G10 | ✅ | Stock alert — threshold ต่อหมวด |
| G11 | ✅ | Mobile/Tablet support — Plan A (tablet-responsive) + Plan B (mobile PO wizard) |

---

## ⏳ Todo

### ด่วน — ก่อนนำเสนอลูกค้า
- [ ] **นำเสนอลูกค้า (ผู้ว่าจ้าง)** — เปิด `http://localhost:8080/admin/index.html` (desktop) + `http://localhost:8080/mobile/purchase.html` (tablet)
- [ ] G1: upload รูปภาพ (หลังเจ้าของเลือก storage: NAS ~10,000฿ / B2 ~12฿/เดือน / Hybrid)
- [ ] Cloudflare Tunnel — remote access dashboard จากมือถือ

### Tech Debt
- [x] ~~JWT_SECRET ย้ายออกจาก apache-config.conf ก่อน production~~ → ย้ายเข้า .env แล้ว
- [x] ~~Rate limiting~~ — DB-based (login_attempts table) ✅
- [x] ~~Token revocation~~ — token_blocklist + jti ✅
- [x] ~~requireAuth()~~ — เพิ่มในทุก controllers ที่ขาด ✅
- [x] ~~Production Audit (54 issues)~~ — 27 fixed, 90% readiness ✅
- [x] ~~CSS cleanup~~ — legacy fonts removed, duplicate @media merged ✅
- [x] ~~seller-history.html~~ — เพิ่มรูป ID card + item thumbnails + lightbox ✅
- [x] ~~Dashboard branch filter~~ — filter ส่งต่อไปทุก table API ✅
- [x] ~~CSV UTF-8 BOM~~ — เพิ่ม \uFEFF ใน client-side exports ✅
- [x] ~~Financial summary~~ — error notifications แทน infinite loading ✅
- [ ] **รัน migration ก่อน deploy:** `code/database/security-migrations.sql`
- [ ] sidebar ใน stock-transfers.html + price-board.html เพิ่มลิงก์เมนูครบ
- [ ] ลบ `code/base-pos/backups/.htaccess` (legacy)
- [ ] เมนู "สาขา" ถูกลบจาก sidebar — ตัดสินใจเอาคืนหรือลบ controller

## 🗑️ Dead Code — อย่าแตะ อย่า restore (ตัดสินใจแล้ว 14 มิ.ย. 2569)

ร้านของเก่าใช้ **Sale Lot เป็นช่องทางขายหลัก** (ขายหน้าร้านนานๆครั้ง) จึงตัด retail POS ออก:

| ไฟล์/ส่วน | สถานะ | หมายเหตุ |
|---|---|---|
| `admin/sales.html` | dead — ไม่ได้ link ใน sidebar | หน้า POS terminal เดิม (base-pos) |
| `api/Controllers/SalesController.php` | dead — routes ถูกลบออกจาก Router แล้ว | requireAuth() ยังอยู่ แต่ไม่มีใครเรียก |
| `api/Models/Sale.php` | ⚠️ ยังเก็บไว้ | `CustomersController` ยังใช้ Sale model |
| Router `sales/*` routes (5 เส้น) | ลบออกแล้ว | createSale, getSales, getSaleDetails, voidSale, exportSales |

**อย่า** เพิ่ม route sales/* กลับเข้าไปใน Router.php โดยไม่ได้ตัดสินใจก่อน

### UX Task — Photo Capture Feature 🚀
- [x] ✅ UX Requirements spec compiled → `docs/UX-REQUIREMENTS-PHOTO-CAPTURE.md`
- [x] ✅ Architect Review (พี่ทรงศักดิ์) — อนุมัติ
- [ ] 🎨 UX Team (08-design) — UI/UX Design
- [ ] 🏗️ Engineering (ช่างฟูล) — Implementation
- [ ] 🧪 QA — Testing

### Phase ถัดไป
- [ ] Reports filter by branch
- [ ] รายงาน: กำไรต่อชิ้น, สินค้าค้างนาน, เปรียบเทียบสาขา, Top 10 ผู้ขาย
- [ ] Hybrid Online/Offline (Phase 4)

---

## 📁 Schema — Migrations

```
001-013  core: branches, sellers, purchase_orders, sale_lots, price_tiers
014      missing indexes
015-016  catalog tier prices (json)
017      sale_lots expenses
018      rename tier labels → บิล1/2/3
019-023  reports, dashboard, responsive, weighted avg cost
024      global categories (merge 4สาขา → 1)
025      default_unit per category
026      seller photo fields
027      business expenses
028      blacklist fields (sellers)
029      precious receipt flag (categories)
030      stock transfers table
031      stock alert threshold (categories)
032      catalog_id + item_name (sale_lot_items)
033-042  security migrations: login_attempts, token_blocklist, rate_limiting
043-044  seller history indexes, migration version fix
045-047  seller search indexes, catalog search optimization
048      (pending)
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
