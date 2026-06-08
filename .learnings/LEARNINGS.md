# Learnings

Pattern-Key + actionable rules เท่านั้น — ไม่มี story, ไม่มี trade-offs
**Category:** correction | insight | best_practice | knowledge_gap

---

## css-transform-rotate-in-modal-is-painful
**Category:** insight
- ❌ อย่าหมุน element ด้วย `transform: rotate()` ใน modal
- ✅ ใช้ `@page { size: A4 landscape; }` ใน `@media print` แทน — browser จัดการเอง

---

## margin-left-auto-for-global-alignment
**Category:** best_practice
- user-dropdown ชิดขวาทุกหน้า → เพิ่ม `margin-left: auto` ใน `.user-dropdown` ที่ layout.css ไฟล์เดียว
- ไม่ต้องแก้ HTML ทีละหน้า

---

## framework-driven-design-system-upgrade
**Category:** best_practice
- ใช้ taste-skill framework ก่อนทำ UX งาน — อ่าน `/skills/redesign-skill/SKILL.md`
- เริ่มจาก Phase 1 (typography) เสมอ — biggest visual ROI
- ใช้ CSS variables สำหรับ design tokens — แก้ครั้งเดียวได้ทั้งระบบ

---

## incremental-ux-refactor-with-utilities
**Category:** best_practice
- สร้าง utilities.css ก่อน แล้วแทนที่ inline styles ทีละจุด
- ใช้ `sed` สำหรับ bulk replacements แทน Edit loop
- Responsive test ด้วย browser DevTools mobile view ก่อน commit

---

## static-review-misses-cross-file-runtime-deps
**Category:** best_practice
- หลัง code review เสร็จ — รัน end-to-end smoke test ก่อนปิด session เสมอ
- Auth/permission fix ต้องเทสด้วย multi-role login (admin + manager + cashier)
- เมื่อ fix พึ่ง field X ของ user → verify ว่า X อยู่ใน JWT payload จริงก่อน trust

---

## value-pricing-over-job-pricing
**Category:** best_practice
- ไม่อาสาลดราคาเอง — ลดเฉพาะเมื่อลูกค้าขอ
- Justify ราคาด้วย service component (on-site, training) ไม่ใช่แค่ code complexity
- SMB ไทย POS custom — ราคาเริ่มต้น 30,000+ ไม่ใช่ของแพง

---

## trust-first-for-burned-customers
**Category:** insight
- ลูกค้าโดนทิ้งงาน 2 ครั้ง → sensitive เรื่อง trust ไม่ใช่ราคา
- Pattern: Demo ฟรี 7 วัน + ส่งมอบ milestone + ซอร์สโค้ดเป็นของลูกค้า
- ❌ อย่าพูดว่า "เกือบครบครับ กำลังพัฒนาอยู่" — trigger trauma เก่า

---

## docker-for-legacy-php-stack
**Category:** best_practice
- PHP+Apache+MySQL → ใช้ Docker Compose เสมอ ไม่ใช่ apt install
- Reset ทุกอย่าง: `docker compose down -v && docker compose up -d`

---

## customization-overlay-pattern
**Category:** best_practice
- legacy PHP static-file stack → direct edit + document changes แพรกติคกว่า overlay
- แก้ base-pos ได้เฉพาะ: config.php, Router.php (patch minimal + comment เหตุผล)

---

## docker-volume-no-rebuild-files
**Category:** best_practice
- PHP/JS/CSS/HTML → save แล้วใช้ได้ทันที ไม่ต้อง rebuild
- Apache config change → `docker compose restart web`

---

## utf8-must-set-at-every-layer
**Category:** correction
- เห็น `????` → ไล่ทีละ layer: Apache → PHP header → PDO → MySQL table → init script
- PDO connection ต้องมี: `PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"`

---

## docker-exec-mysql-charset-flag-required
**Category:** correction
- ทุกครั้งที่รัน `docker exec ... mysql ... -e` กับข้อมูลภาษาไทย:
```bash
docker exec secondhand-pos-db mysql -uroot -prootpass --default-character-set=utf8mb4 pos_system -e "UPDATE ..."
```
- เช็คผล: `SELECT HEX(name) FROM ...` — UTF-8 ไทยที่ถูกต้องเริ่มด้วย `E0Bx`

---

## no-action-beyond-explicit-scope
**Category:** feedback
- ถ้าพูด "บอกได้ครับ" / "รออนุญาต" → หยุดที่นั่น ห้ามทำในก้อน tool call ต่อไป
- ทำตรงตามที่สั่ง ไม่บวกขอบเขตเอง

---

## dashboard-pivot-from-sales-to-purchase
**Category:** best_practice
- แปลง POS ขายของ → POS รับซื้อ: เพิ่ม purchase queries ข้างๆ ไม่ต้องลบ sales code
- Tables: purchase_orders, purchase_order_items, sellers, item_conditions

---

## react-to-vanilla-php-port-strategy
**Category:** best_practice
- อ่าน React source ก่อน → model HTML+JS ตาม existing PHP page (เช่น purchase-orders)
- เช็ค common.js ก่อนเขียน utility function ใหม่
- Modal pattern: `.modal` / `.modal-content` / `.modal.show`

---

## sidebar-two-styles-in-same-project
**Category:** insight
- base-pos มี sidebar HTML 2 แบบ: short (one-liner) และ long (multi-line)
- เพิ่มลิงก์ sidebar → ต้องแก้ทั้ง 9 pages ด้วย exact string match ของแต่ละ style
- grep ก่อนเสมอเพื่อดูว่าหน้าไหนใช้ style ไหน

---

## html-data-attribute-json-encoding-bug
**Category:** correction
- ❌ อย่าใช้ `escapeHtml()` กับ JSON ที่จะเก็บใน data-attribute → `JSON.parse()` ล้มเหลวเงียบๆ
- ✅ ใช้ closure ส่ง object ตรงๆ แทน:
```javascript
div.addEventListener('click', () => selectCatalogItem(it));
```

---

## global-state-must-be-set-before-dependent-function-call
**Category:** correction
- set state ก่อนเรียก function ที่อ่านค่านั้นเสมอ:
```javascript
currentCatalogItem = { ...item, tierPrices }; // set ก่อน
buildTierButtons(tierPrices);                 // แล้วค่อย call
```

---

## rewrite-beats-incremental-patching-when-broken
**Category:** best_practice
- สัญญาณควร rewrite: แก้แล้วไม่ทำงาน ≥2 รอบ / มี workaround ซ้อน >2 ชั้น / มี debug code ค้าง
- เมื่อ rewrite → อ่านไฟล์ทั้งหมดก่อน normalize data format ให้ consistent ตั้งแต่ต้น

---

## js-referenced-element-must-exist-in-html
**Category:** correction
- JS ที่ set innerHTML/textContent → grep HTML ว่า id นั้นมีจริงก่อนเสมอ
```bash
grep -o "getElementById('[^']*')" file.js | sort -u
grep -o 'id="[^"]*"' file.html | sort -u
```

---

## docker-mysql-credentials-secondhand-pos
**Category:** knowledge_gap
- Container: `secondhand-pos-db` | DB: `pos_system`
- User: `posuser/pospass` | Root: `rootpass`
- API base: `http://localhost:8080/api/index.php/{route}`

---

## tier-buttons-visible-first
**Category:** insight
- ปุ่มระดับราคา (บิล1/2/3) ต้อง visible ตั้งแต่โหลดหน้า — user เลือก tier ก่อน add items
- Default labels = "บิล1/2/3" → อัปเดตเมื่อเลือก catalog item ที่มี tier_prices

---

## drop-fk-before-drop-column
**Category:** correction
```sql
-- ✅ ลำดับที่ถูก
ALTER TABLE categories DROP FOREIGN KEY fk_categories_branch;
ALTER TABLE categories DROP COLUMN branch_id;
-- เช็ค constraint name: SHOW CREATE TABLE categories;
```

---

## merge-duplicates-remap-foreign-keys
**Category:** best_practice
- remap FK references ก่อนลบ duplicates เสมอ — ไม่งั้น orphaned records
- ใช้ temp mapping table: `old_id → canonical_id (MIN(id) per name)`

---

## localstorage-branch-selection-banner
**Category:** best_practice
- การเลือกที่สำคัญ (สาขา, warehouse) → persist ด้วย localStorage
- แสดง banner ชัดเจน — อย่าให้ user ต้องมองหา dropdown

---

## strict-vs-relaxed-validation
**Category:** best_practice
- ถาม user ก่อนว่า "ห้ามเท่ากัน" หรือ "ห้ามกลับด้าน" — อย่าเดาเอง
- แก้ทั้ง backend และ frontend ให้ตรงกันเสมอ

---

## category-default-unit-autofill
**Category:** best_practice
- เพิ่ม `default_unit VARCHAR(20)` ใน categories → auto-fill หน่วยตอนเลือกหมวด
```javascript
const defaultUnit = categorySelect.selectedOptions[0]?.dataset.unit || 'ชิ้น';
unitInput.value = defaultUnit;
```

---

## api-returns-data-frontend-not-rendering
**Category:** correction
- ก่อนสรุปว่า backend ไม่ update → curl API ดู response ก่อนเสมอ
- trace data flow ครบ: DB → Model → Controller → API → JS fetch → DOM render

---

## api-401-confirms-route-found
**Category:** knowledge_gap
- 401 = route ถูกต้อง แต่ต้อง auth token
- 404 = route ไม่ match → เช็ค Router.php หรือ URL path

---

## apipath-includes-index-php
**Category:** correction
- API URL ที่ถูกต้อง: `http://localhost:8080/api/index.php/{route}`
- ❌ `/api/{route}` → 404 เสมอ
