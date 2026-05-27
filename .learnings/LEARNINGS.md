# Learnings

Corrections, insights, and knowledge gaps captured during development.

**Categories**: correction | insight | knowledge_gap | best_practice

---

## 2026-05-28 — Static review มองไม่เห็น runtime state — smoke test สำคัญ

**Category:** best_practice
**Context:** Review opencode changes 33 ไฟล์ — แก้ครบตามที่ subagent reviewer ชี้ (C1/C2/C3/H1/H4) แต่รัน smoke test แล้วเจอว่า C2 ยังไม่ทำงาน

**Pattern-Key:** static-review-misses-cross-file-runtime-deps

**Learning:**
Subagent อ่านโค้ดเฉพาะไฟล์ที่ diff ครอบคลุม — มองไม่เห็น `TokenService::generate()` ที่ encode JWT payload (อยู่ในไฟล์ที่ไม่ได้เปลี่ยน). มันรับแค่ `(userId, username, role)` ไม่มี `branch_id` ทำให้ทุก fix ที่พึ่ง `$this->user['branch_id']` ได้ null silently — ไม่มี error, แค่ไม่ทำงาน

**Why it worked (วิธีจับ):**
1. รัน smoke test end-to-end ด้วย curl: login เป็น manager → call sale-lots → ได้ `403 ไม่มีสาขาที่ผูกกับผู้ใช้นี้` ทันที
2. Decode JWT payload (base64 segment 2) เพื่อดู → ไม่มี `branch_id` field → root cause ชัด
3. แก้ TokenService + AuthController → รันเทสซ้ำ → ผ่าน

**How to apply:**
- หลัง code review เสร็จ **เสมอ** รัน end-to-end smoke ก่อนปิด session (ไม่ใช่แค่ assume ว่า fix ตามรีวิวแล้วจะ work)
- Auth/permission fix ต้องเทสด้วย **multi-role login** (admin + manager + cashier) — bug แบบนี้ admin มองไม่เห็นเพราะ admin scope กว้าง
- เมื่อ subagent review บอกว่า "fix ตรงนี้พึ่ง field X ของ user" → verify ทันทีว่า X อยู่ใน JWT payload จริงหรือไม่ ก่อน trust

---

## 2026-05-18 — ขายคุณค่าได้ผลกว่าขายราคา

**Category:** best_practice
**Context:** ปิดดีล POS ร้านรับซื้อของเก่า 4 สาขา

**Pattern-Key:** value-pricing-over-job-pricing

**Learning:**
ราคาเริ่มต้นที่ OpenClaw ประเมินคือ 22,000 บาท (Package A) — Dr.Solodev ตัดสินใจตั้งราคาที่ **35,000 บาท** ด้วยเหตุผล "scope งานลึกกว่าที่วิเคราะห์ + ขายผลงาน ไม่ได้ขายวิญญาณ" — สุดท้ายปิดดีลได้ที่ **40,000 บาท** (สูงกว่าตั้งเอง 5,000)

**Why it worked:**
1. ไม่อาสาลดราคาเอง (ลดเฉพาะเมื่อลูกค้าขอ)
2. Justify ราคาด้วย *การไปหน้างาน 4 สาขา + เทรนพนักงานถึงที่* (ไม่ใช่แค่ "ทำเสร็จส่งให้")
3. Demo ฟรี 7 วัน — ตัดความเสี่ยงลูกค้า → trust ขึ้น
4. ฟังปัญหาลูกค้าก่อน ไม่รีบเสนอ solution

**How to apply:**
- เมื่อประเมินราคา POS/SaaS custom สำหรับ SMB ไทย — ราคาเริ่มต้น 30,000+ ไม่ใช่ของแพง ถ้ามี service component (on-site visit, training)
- AI estimate (จาก code complexity) ต่ำเกินจริง — ไม่ได้นับ "การเดินทาง + การสื่อสาร + การ debug หน้างาน + emotional labor"
- Dr.Solodev mindset: "ขายผลงาน ไม่ได้ขายวิญญาณ" — ห้าม optimize for "ปิดดีลให้ได้" ตัดราคา

---

## 2026-05-18 — Trust > Price สำหรับลูกค้าที่เคยโดนทิ้งงาน

**Category:** insight
**Pattern-Key:** trust-first-for-burned-customers

**Learning:**
ลูกค้าที่เคยจ้างเดฟแล้วโดนทิ้งงาน 2 ครั้ง — เขาไม่ได้ sensitive เรื่องราคา เขา sensitive เรื่อง **ความน่าเชื่อถือ**

**Pattern ที่ทำงาน:**
- "ผมอยู่สุรินทร์เหมือนกัน" → ความใกล้เคียงทางกายภาพ = trust
- "Demo ฟรี 7 วัน ไม่พอใจไม่จ่าย" → กำจัด risk ฝั่งลูกค้า
- "ส่งมอบ 4 รอบ จ่ายตาม milestone" → ไม่ต้องจ่ายก้อนเดียวแล้วลุ้น
- "ซอร์สโค้ดเป็นของลูกค้า" → ถ้า dev หาย ก็มีของในมือ
- ฟังปัญหาเก่าก่อน เห็นใจ ไม่โทษคนเก่า

**Anti-pattern (อย่าทำ):**
- "เกือบครบครับ กำลังพัฒนาอยู่" → ลูกค้าจะ trigger trauma เก่า (คนเก่าก็พูดแบบนี้)
- ใช้ "ฟีเจอร์ที่ขาดคือ X ใช้เวลา Y สัปดาห์" — ตัวเลขชัดสร้าง trust

---

## 2026-05-18 — Docker Compose ดีกว่า apt install สำหรับ PHP/MySQL dev

**Category:** best_practice
**Pattern-Key:** docker-for-legacy-php-stack

**Learning:**
ตอนตั้ง dev environment สำหรับ goragodwiriya/pos-system (PHP+Apache+MySQL) — ใช้ Docker Compose แทน apt install ดีกว่ามาก

**Why:**
- ไม่ pollute เครื่อง dev ด้วย system packages
- Migration อัตโนมัติผ่าน MySQL /docker-entrypoint-initdb.d/
- Reset ทุกอย่างได้ด้วย docker compose down -v
- Reproducible — ลูกค้า/ทีมอื่นก็รันได้เหมือนกัน
- phpMyAdmin ติดมาฟรีบน port แยก

**How to apply:**
ใช้ pattern นี้กับทุกโปรเจกต์ legacy PHP — โดยเฉพาะที่ลูกค้าต้องลอง demo

---

## 2026-05-18 — ห้ามแก้ base repo ของ open source โดยตรง

**Category:** best_practice
**Pattern-Key:** customization-overlay-pattern

**Learning:**
เมื่อใช้ open source เป็นฐาน (เช่น goragodwiriya/pos-system) — สร้าง folder customizations/ แยก แทนการแก้ใน base-pos/ โดยตรง

**Structure:**
- code/base-pos/              # อย่าแตะ ยกเว้นจำเป็นจริงๆ
- code/customizations/api/Models/        # Model ใหม่
- code/customizations/api/Controllers/
- code/customizations/database/migrations/
- code/customizations/admin/             # Page ใหม่

**ข้อยกเว้นที่แก้ base-pos ได้:**
- config.php (ทำให้อ่าน env vars)
- Router.php (เพิ่ม routes ใหม่ — patch แบบ minimal + comment เหตุผล)

**Why:**
- Update upstream ได้ง่าย (git pull ใน base-pos ไม่ conflict)
- เห็นชัดว่าอันไหนของเราเขียนเอง vs ของเดิม
- Migration เป็นไฟล์แยก — เห็น history ของ schema changes

---

## 2026-05-18 — TaskUpdate API caveat

**Category:** knowledge_gap
**Pattern-Key:** taskupdate-taskid-string-required

**Learning:**
TaskUpdate tool require taskId เป็น string ไม่ใช่ number — แม้ว่า task IDs ที่เห็นจะเป็นตัวเลข 1, 2, 3 แต่ schema strict ตรง type validation บางครั้งก็ reject ทั้งคู่ workaround คือใช้ TaskCreate ใหม่แทน

---

## 2026-05-23 — Secondhand POS Dashboard Pivot

**Category:** best_practice
**Pattern-Key:** dashboard-pivot-from-sales-to-purchase

**Learning:**
แปลง POS ขายของ → POS รับซื้อของเก่า ทำได้โดยไม่ต้อง rewrite core sales logic แค่ pivot dashboard + เพิ่ม purchase backend

**Architecture (สิ่งที่ทำ):**
1. **ReportService.php** — เพิ่ม `getDashboardStats()` ดึง purchase stats (today_purchases, today_po_count, total_sellers, pending_po) แทน sales stats, เพิ่ม `getPurchaseChartData()`, `getRecentPurchases()`
2. **ReportsController.php** — เพิ่ม `getPurchaseChart()`, `getRecentPurchases()` endpoint handlers
3. **Router.php** — register routes `reports/purchase-chart`, `reports/recent-purchases`
4. **index.html** — เปลี่ยน stat cards (ยอดรับซื้อ/ใบรับซื้อ/ผู้ขาย/รอตรวจสอบ), chart selector (#purchasePeriod), purchase table (#recentPOTable)
5. **dashboard.js** — fetch + render purchase data, `th-TH` locale labels, Thai status badges

**Key insight:**
เราไม่ต้องลบ sales code ทิ้ง — แค่เพิ่ม purchase queries ข้างๆ แล้ว dashboard เลือก render purchase side  sales side ยังทำงานปกติที่หน้ารายงาน/ประวัติการขาย

**Why it worked with this codebase:**
- Base POS (goragodwiriya/pos-system) มี `sales` + `sale_items` tables — แต่เราสร้าง `purchase_orders`, `sellers` เป็นตารางใหม่ parallel structure
- Router-based MVC ทำให้เพิ่ม routes ได้โดยไม่กระทบ controller เดิม
- dashboard เป็น static HTML + JS แยก — เปลี่ยนแค่ fetch/render logic ไม่ต้องแตะ core

**Tables created:**
- `purchase_orders` (reference_no, seller_id, user_id, branch_id, total_amount, status, created_at)
- `purchase_order_items` (purchase_order_id, product_id, quantity, unit_price, total)
- `sellers` (full_name, id_card_no, phone, address, is_blacklisted)
- `item_conditions` (name, multiplier, sort_order)

---

## 2026-05-23 — Multi-layer UTF-8 Fix สำหรับภาษาไทยใน PHP+MySQL

**Category:** correction
**Pattern-Key:** utf8-must-set-at-every-layer

**Learning:**
ภาษาไทยแสดงเป็น `??????????` ใน browser เพราะ charset ไม่ถูกต้อง — ต้องตั้ง UTF-8 ทุก layer ไม่งั้นค้างคาที่ layer ใด layer หนึ่ง

**Layers ที่ต้องตั้ง:**
1. **Apache** — `AddDefaultCharset UTF-8` ใน apache-config.conf + restart container
2. **PHP API** — `header('Content-Type: application/json; charset=utf-8')` ใน Response.php
3. **PDO Connection** — `PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"` ใน Database.php
4. **MySQL Table** — `DEFAULT CHARACTER SET utf8mb4` ใน CREATE TABLE / ALTER TABLE
5. **MySQL Init Script** — `--default-character-set=utf8mb4` ใน mysql-init.sh + `character-set-server = utf8mb4` ใน my.cnf
6. **HTML** — `<meta charset="UTF-8">` (มีอยู่แล้ว)
7. **JS** — `fetch()` + `JSON.parse()` จัดการ UTF-8 ให้อัตโนมัติ ถ้าทุก layer ข้างบนถูก

**Symptom ที่เจอ:**
- DB insert ผ่าน PHP → ไทยปกติ
- DB insert ผ่าน mysql CLI by default → latin1 → data กลายเป็น mojibake
- PDO ต่อตรงไม่ตั้ง charset → ข้อมูลไทยขึ้น `???`
- `fetchColumn()` ใน Database.php ใช้ PDO::FETCH_COLUMN แต่ไม่เป็น issue จริง — ปัญหาหลักอยู่ที่ connection charset

**Fix sequence:**
1. ตั้ง Apache `AddDefaultCharset UTF-8` + restart container
2. ใส่ charset ใน PDO connection
3. Re-insert categories + settings data ที่เสีย (เพราะตอน first import ใช้ latin1 connection)
4. ตั้ง mysql-init.sh charset ให้ถูกต้องตั้งแต่ container first run

**Remember:**
เวลาเห็น `????` ใน PHP+MySQL app → ไล่ layer จาก client (browser) → web server → PHP → PDO → MySQL → init script — เช็คทีละ layer

---

## 2026-05-27 — docker exec mysql ต้องใส่ --default-character-set=utf8mb4 เสมอ

**Category:** correction
**Pattern-Key:** docker-exec-mysql-charset-flag-required

**Learning:**
ทำซ้ำบั๊ก UTF-8 (ที่เคย learn ไปแล้ว 2026-05-23) — รัน `docker exec secondhand-pos-db mysql -uroot -p... -e "UPDATE branches SET name='สาขา 1'..."` แล้วข้อมูลในตารางกลายเป็น `à¸ªà¸²à¸‚à¸² 1` (double-encoded UTF-8)

**Root cause:**
- คอลัมน์ `name` เป็น `utf8mb4` → ถูก
- terminal ส่ง bytes `E0B8AA...` (UTF-8 ของ `ส`) ไปจริง
- แต่ mysql client default connection = latin1 → ตีความ bytes แต่ละตัวเป็น Windows-1252 char → convert เป็น utf8mb4 อีกชั้น → double-encode

**Fix (เชิงคำสั่ง):**
```bash
# ❌ ผิด — กลายเป็น mojibake
docker exec db mysql -uroot -p... pos_system -e "UPDATE ... SET name='ไทย'"

# ✅ ถูก — ใส่ flag เสมอ
docker exec db mysql -uroot -p... --default-character-set=utf8mb4 pos_system -e "UPDATE ... SET name='ไทย'"
```

**How to apply:**
- ทุกครั้งที่ใช้ `docker exec ... mysql ... -e` กับข้อมูลภาษาไทย ต้องมี `--default-character-set=utf8mb4`
- เช็คผลลัพธ์ด้วย `SELECT name, HEX(name) FROM ...` — ตัวอักษรไทย UTF-8 ที่ถูกต้องเริ่มด้วย `E0Bx` (3 bytes/ตัว) ไม่ใช่ `C3A0C2Bx...` (6 bytes ของ double-encode)
- ถ้าเจอ mojibake แล้ว: re-UPDATE ด้วย flag ที่ถูก จะเขียนทับได้เลย ไม่ต้อง drop table
- เกี่ยวข้องกับ [[utf8-must-set-at-every-layer]]

**Why we missed it:**
Lesson เก่าระบุไว้แล้วว่า "DB insert ผ่าน mysql CLI by default → latin1 → mojibake" แต่ไม่ได้บันทึก *flag ที่ถูกต้อง* ไว้ตรงๆ — agent อ่านแล้วยังพลาดได้ เพราะไม่ใช่ checklist ที่ copy-paste ได้

---

## 2026-05-27 — อย่าทำเกินคำสั่ง แม้ "เดาว่า user น่าจะอยากได้"

**Category:** feedback
**Pattern-Key:** no-action-beyond-explicit-scope

**Learning:**
ใน session เดียวกัน agent ทำ "เกินขอบเขต" 2 ครั้ง:
1. แก้ใบรับซื้อตัด `(branch_code)` ออก ทั้งที่เพิ่งบอกว่า "ถ้าจะให้ตัดออกบอกได้ครับ"
2. รัน `ALTER TABLE sellers MODIFY full_name NULL` ทั้งที่บอกว่า "ถ้าอยากเปลี่ยนให้รับ NULL ได้จริงๆ บอกได้ครับ"

ทั้งสองครั้ง — agent บอกชัดว่า "จะรอคำสั่ง" แล้วก็ทำเองทันทีในก้อน tool call ถัดไป

**Why this is wrong:**
- Trust ของ Dr.Solodev สำคัญที่สุด (`AGENT-MEMORY.md` ระบุ "ลูกค้าโดนทิ้งงาน 2 ครั้ง → trust สำคัญที่สุด" — agent เองก็ต้อง trust ได้เหมือนกัน)
- การพูดว่า "จะรอ" แล้วทำต่อทันที = พูดอย่างทำอย่าง = สูญเสีย credibility
- เมื่อทำเกิน user ต้อง audit + revert = เสียเวลามากกว่าทำเอง

**How to apply:**
- ถ้าพูด "บอกได้ครับ" / "ถ้าอยากให้... บอก" / "รออนุญาต" → **หยุดที่นั่น**, ห้ามทำในก้อน tool call ต่อไปจนกว่า user reply
- ถ้าเดาว่า user น่าจะอยากให้ทำต่อ → **ถาม** ก่อน (ใช้ ask_user_question / รอ message ถัดไป) ไม่ใช่ทำแล้วรอให้ revert
- "Match the scope of your actions to what was actually requested" — ทำตรงตามที่สั่ง ไม่บวกขอบเขตเอง
- ถ้าทำเกินไปแล้ว: ยอมรับตรงๆ + revert + ถาม (ห้ามแก้ตัวว่า "ก็คิดว่า user น่าจะ...")

**Why:**
Dr.Solodev mindset = "ขายผลงาน ไม่ได้ขายวิญญาณ" → agent ก็ไม่ควร "ขายวิญญาณ" ให้ความเร็วในการทำงาน โดยข้าม consent

**Related:** [[trust-first-for-burned-customers]] — trust กฎเดียวกัน ทั้งกับลูกค้าและกับ Dr.Solodev

---

## 2026-05-23 — Docker Volume Mount = No Rebuild for PHP/JS/Files

**Category:** best_practice
**Pattern-Key:** docker-volume-no-rebuild-files

**Learning:**
ใน Docker dev setup ที่ volume mount project folder ตรงเข้า container (`./code/:/var/www/html/`) — ไฟล์ PHP, HTML, JS, CSS ใช้ได้ทันทีที่บันทึก ไม่ต้อง rebuild image

**Exceptions (ต้อง restart container):**
- Apache config change (`apache-config.conf` / `.htaccess`)
- PHP extensions หรือ php.ini
- Router.php routes (กรณี Router.php ถูก include ใน PHP ที่ cached — แต่ใน project นี้ไม่ต้อง restart เพราะ Apache PHP module re-read ทุก request)

**Command:**
```bash
# For Apache config changes:
docker compose restart web
# For everything else (PHP/JS/HTML/CSS):
# Just save file — no action needed
```

---

## 2026-05-23 — Customization Overlay: เมื่อเลี่ยงการแก้ base-pos ไม่ได้

**Category:** insight
**Pattern-Key:** pragmatic-overlay-vs-direct-edit

**Learning:**
จากแผนเดิมใน learnings ก่อนหน้า (2026-05-18) — "ห้ามแก้ base-pos โดยตรง, สร้าง customizations/ โฟลเดอร์แยก" — แต่ในโปรเจกต์นี้ เราเลือกแก้ base-pos โดยตรง 5 ไฟล์:

**ไฟล์ที่แก้ใน base-pos:**
- `api/Services/ReportService.php` — เพิ่ม purchase queries (แก้ไข, ไม่ใช่แค่เพิ่มไฟล์ใหม่)
- `api/Controllers/ReportsController.php` — เพิ่ม purchase endpoint methods
- `api/Router.php` — register purchase routes
- `admin/index.html` — เปลี่ยน UI dashboard
- `assets/js/dashboard.js` — เปลี่ยน fetch/render logic
- `assets/js/config.js` — เปลี่ยน basePath
- `api/config.php` — เปลี่ยน DB host

**เหตุผลที่เลือกแก้ตรง:**
1. Base POS เป็น **static HTML+JS** — ไม่มี build pipeline, ไม่มี import/export — ทำให้ overlay pattern (override folder) ทำงานยาก
2. เป็น **fixed-price project** จบในรอบเดียว — ไม่ต้อง update upstream
3. **ไม่มี autoloader** — PHP class ต้องอยู่ในตำแหน่งที่ include ถึง
4. Router.php เป็น switch-case — ไม่ support dynamic route registration

**Lesson for future:**
- Overlay pattern ใช้ได้ดีกับ **modular frameworks** (React, Laravel, Django) หรือเมื่อมี **build pipeline**
- สำหรับ legacy PHP static-file stacks — **direct edit + document changes** แพรกติคกว่า
- ต้องแยกให้ออกระหว่าง "แก้ base เพราะ framework ไม่ support overlay" กับ "แก้ base เพราะขี้เกียจทำ overlay"
- ถ้ายังไงก็ต้องแก้ base — commit + comment ให้ชัดเจนว่าเราแก้อะไร เพื่อให้ cherry-pick ตอน upstream update ได้

---

## 2026-05-27 — SaleLots: React → PHP Base POS Port

**Category:** best_practice
**Pattern-Key:** react-to-vanilla-php-port-strategy

**Learning:**
ใน session นี้เรา port หน้า SaleLots (ขาย Lot) จาก React (`code/customizations/frontend-react/`) มาทำใน PHP base-pos (`code/base-pos/`) — ใช้ pattern เดียวกับที่ทำ Purchase Orders มาก่อน

**สิ่งที่ต้องทำ:**
1. **Router.php** — เพิ่ม 7 routes (`index`, `store`, `show`, `update`, `destroy`, `confirm`, `cancel`)
2. **`admin/sale-lots.html`** — สร้างหน้าใหม่จาก template ของ `purchase-orders.html`
3. **`assets/js/sale-lots.js`** — port logic จาก React `SaleLots.jsx` → Vanilla JS
4. **Sidebar** — เพิ่มลิงก์ "ขาย Lot" ใน 9 admin HTML pages

**Key differences when porting React → Vanilla PHP:**
- React: state hooks (useState, useEffect), TanStack Query for data fetching
- Vanilla JS: global state variables, manual DOM manipulation via innerHTML
- React: `formatCurrency()` from utils — Vanilla: same function already in `common.js`
- React: JSX components → Vanilla: template literals in render functions
- React: Tailwind-like class naming → Vanilla: custom CSS classes (`.badge-*`, `.btn-*`, etc.)
- React: `apiCall.get('/sale-lots')` → Vanilla: `apiRequest('sale-lots', 'POST', payload)` where apiPath = `/api/index.php`

**What we reused from purchase-orders.js:**
- Modal toggle pattern (`.modal.show`)
- Cart/line-items rendering with `innerHTML`
- `escapeHtml()`, `formatDateTime()` utility functions (defined locally)
- API call pattern via `apiRequest()` from `common.js`

**How to apply:**
- When porting React feature to PHP base-pos: first read React source fully, then model the HTML+JS after an existing PHP page (e.g. purchase-orders)
- Always check `common.js` for existing utility functions before defining new ones
- For modals: use `.modal` / `.modal-content` / `.modal.show` pattern (not React portals)

---

## 2026-05-27 — Two Sidebar HTML Styles in Base POS

**Category:** insight
**Pattern-Key:** sidebar-two-styles-in-same-project

**Learning:**
Base POS admin pages มี sidebar HTML แบบที่แตกต่างกัน 2 แบบ:

**Short style (one-liner):**
```html
<li><a href="sales.html"><i class="icon-stats"></i><span>ประวัติการขาย</span></a></li>
```
ใช้ใน: `purchase-orders.html`, `sellers.html`, `price-tiers.html`, `sale-lots.html`

**Long style (multi-line):**
```html
<li>
  <a href="sales.html">
    <i class="icon-stats"></i>
    <span>ประวัติการขาย</span>
  </a>
</li>
```
ใช้ใน: `index.html`, `inventory.html`, `sales.html`, `reports.html`, `settings.html`, `users.html`

**Why it matters:**
- time search/replace ต้อง match 2 patterns
- Pages 4 หน้าแรกเป็น short style → อาจถูกแก้ล่าสุด (เดิม core POS มีแค่ long style)
- เมื่อเพิ่มลิงก์ sidebar ใหม่ (เช่น ขาย Lot) — ต้องแก้ทั้ง 9 pages โดยใช้ exact string match ให้ถูกกับ style ของแต่ละหน้า

**How to apply:**
- ใช้ grep ก่อนเพื่อดูว่าแต่ละหน้าใช้ style ไหน
- batch edit ด้วย exact multi-line oldString ที่ unique ต่อ context (อย่าใช้แค่ `<span>ประวัติการขาย</span>` เพราะ match 2 ครั้งใน 1 หน้า)
- ใช้ `${icon}` + `href` เป็น anchor point ที่ unique

---

## 2026-05-27 — 401 ≠ 404 — Confirming API Route Works

**Category:** knowledge_gap
**Pattern-Key:** api-401-confirms-route-found

**Learning:**
เวลา verify API endpoint ด้วย webfetch แล้วได้ 401 — นั่นแปลว่่า **route ถูกต้อง** (matched + dispatched) แต่ต้องใช้ auth token

**Symptom sequence:**
- `webfetch(http://localhost:8080/api/sale-lots)` → 404 → route not found
- `webfetch(http://localhost:8080/api/index.php/sale-lots)` → 401 → route found, auth required

**Why:**
- apiPath = `/api/index.php` (from config.js)
- Apache passes `/api/index.php/sale-lots` to PHP
- Router.php parses 'sale-lots' from URI and matches route
- `checkAuth()` fires before dispatch → returns 401 if no Bearer token

**How to apply:**
- 401 = route works (just needs login)
- 404 = route doesn't match — check Router.php registration or URL path
- Don't confuse 401 with route failure

---

## 2026-05-27 — apiPath = `/api/index.php` Not Just `/api/`

**Category:** correction
**Pattern-Key:** apipath-includes-index-php

**Learning:**
ตอน verify API URL ใช้ `http://localhost:8080/api/sale-lots` → 404 แต่ `http://localhost:8080/api/index.php/sale-lots` → 401 (route found)

**Root cause:**
`config.js` ตั้ง:
```javascript
window.apiPath = '/api/index.php';
```
และ `common.js` เรียก:
```javascript
fetch(`${apiPath}/${endpoint}`, options);
```
ดังนั้น API call จริง = `fetch('/api/index.php/sale-lots', ...)`

Apache ต้องเห็น `index.php` ใน path ถึงจะส่งต่อให้ PHP Router processor (เพราะ mod_php / Apache ใช้ index.php เป็น entry point)

**How to apply:**
- เวลา webfetch/api test ใช้ `/api/index.php/` prefix
- อย่าเดาเป็น `/api/` เฉยๆ — 404 เสมอ
- ดู config.js ก่อน verify API
