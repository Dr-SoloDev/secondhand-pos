> **⚠️ Timeline ถูก supersede แล้ว** — ดูแผนใหม่: [`docs/OPS-DEPLOYMENT-PLAN.md`](OPS-DEPLOYMENT-PLAN.md)
> สถานะ: **NO-GO** จนกว่า 3 blockers จะปิด (G3 SQL, admin/admin, HTTPS)

# 📋 JOB ORDER: PHP Full-stack Engineer — Day 3

**ส่งถึง:** PHP Full-stack Engineer  
**จาก:** System Architect  
**กำหนดส่ง:** วันสิ้นสุด Day 3 (23:59 น.)  
**รับต่อจาก:** Backend Security Engineer (Day 2)  
**Handoff ให้:** UX/Frontend Engineer (Day 4)

---

## Pre-condition (ต้องได้จาก Day 2 ก่อนเริ่ม)

```
□ Error message leak fixed — no stack trace in response
□ Cart state protection — sessionStorage persist + restore
□ Idempotency key — PO + Sale Lot, migration 041
□ Rate limit verified — 5 attempts / 15 min / IP
□ Error log clean (ไม่เกิน 5 ERROR/FATAL ใน 2 ชม.หลัง deploy)
```

**ถ้าข้อไหนยังไม่ผ่าน → หยุด, แจ้ง Architect, อย่าเริ่ม**

---

## ภารกิจหลัก

ทำให้ Business Flow หลัก (รับซื้อ → ขาย Lot) ใช้งานได้ **เรียบง่าย ไม่สะดุด** — โฟกัสที่ Purchase Form เป็นหลัก เพราะคือหน้าจอที่พนักงานใช้ทั้งวัน

| Priority | Task | เวลา | ความเสี่ยงถ้าไม่ทำ |
|----------|------|------|--------------------|
| P0 | Purchase form simplification | 4 ชม. | พนักงานสับสน กรอกราคาผิด เสียเวลา |
| P1 | FIFO costing end-to-end test | 2 ชม. | กำไร Lot คำนวณผิด ไม่รู้ตัว |
| P1 | Employee module (G4) smoke test | 1 ชม. | เงินเดือน + ประกันสังคมไม่เชื่อม expenses |
| P2 | Sale Lot transport cost verify | 1 ชม. | ค่าขนส่งไม่แสดงใน summary |

---

## Task 1: Purchase Form Simplification (4 ชม.)

### ปัญหา (จาก PRD STEP 3 + Feedback เจ้าของร้าน)

**ปัจจุบัน:** เมื่อเลือกสินค้าจาก catalog, ราคา auto-fill จาก tier อยู่แล้ว (`purchase-orders.js` บรรทัด 276-281) แต่ **UI ยังแสดงช่อง `ราคาต่อหน่วย` (itemUnitPrice) ให้เห็นและแก้ไขได้** — ทำให้พนักงาน:
- คิดว่าต้องกรอกราคาเอง
- เผลอกดเปลี่ยน → ราคาคลาดเคลื่อน
- เสียสมาธิ เพราะต้อง focus หลายช่องเกินไป

**ตาม PRD STEP 3 (Flow ที่ถูกต้อง):**
```
3.1 เลือก Tier (1/2/3) ← ราคาจะ auto-fill
3.2 คีย์รหัสสินค้า → ชื่อ + หมวดหมู่ เด้งอัตโนมัติ
3.3 กรอกน้ำหนัก (kg)
3.4 กรอกหักน้ำหนัก (สิ่งเจือปน)
3.5 [+ เพิ่ม] → เข้า Cart
```

### สิ่งที่ต้องทำ

#### 1.1 ซ่อน `itemUnitPrice` input — เปลี่ยนเป็น read-only display

**ไฟล์:** `code/base-pos/admin/purchase-orders.html`

หา element `itemUnitPrice` และ `itemPriceTier` group — ปรับให้:
- `itemUnitPrice` input → เปลี่ยนเป็น `<span class="auto-price-display">` แสดงราคาที่ auto-fill (หรือซ่อนไปเลย)

โครงสร้าง add-row ใหม่ควรเป็น:
```html
<!-- แถว 1: ชื่อสินค้า + ค้นหา -->
<div class="po-field-row">
  <div class="po-field po-field-name">
    <label>สินค้า</label>
    <input type="text" id="itemName" placeholder="พิมพ์รหัสหรือชื่อสินค้า...">
    <div id="itemCatalogResults" class="catalog-dropdown"></div>
    <input type="hidden" id="itemCatalogId">
    <input type="hidden" id="itemCategoryId">
  </div>
  <div class="po-field po-field-unit">
    <label>หน่วย</label>
    <input type="text" id="itemUnit" value="กก." readonly style="background:#f5f5f5">
  </div>
  <div class="po-field po-field-photo">
    <label>&nbsp;</label>
    <button id="itemPhotoBtn" class="btn-photo-picker" onclick="openPhotoPicker('new-item')" title="ถ่ายรูปสินค้า" type="button">📷</button>
    <span id="itemPhotoIndicator"></span>
  </div>
</div>

<!-- แถว 2: น้ำหนัก + หักน้ำหนัก + ราคาอ่านอย่างเดียว -->
<div class="po-field-row">
  <div class="po-field po-field-weight">
    <label>น้ำหนัก (กก.)</label>
    <input type="number" id="itemQuantity" step="0.01" min="0" placeholder="0.00">
  </div>
  <div class="po-field po-field-deduct">
    <label>หักน้ำหนัก (กก.)</label>
    <input type="number" id="itemWeightDeduct" step="0.01" min="0" placeholder="0.00">
  </div>
  <div class="po-field po-field-price-display">
    <label>ราคา/กก.</label>
    <div id="itemPriceDisplay" class="auto-price-display">—</div>
  </div>
  <div class="po-field po-field-total">
    <label>รวม</label>
    <div id="itemTotalPreview" class="auto-price-display">เลือกสินค้า</div>
  </div>
  <div class="po-field po-field-addbtn">
    <label>&nbsp;</label>
    <button id="addItemBtn" class="btn btn-primary" type="button">+ เพิ่ม</button>
  </div>
</div>
```

#### 1.2 ปรับ JavaScript ให้สอดคล้อง

**ไฟล์:** `code/base-pos/assets/js/purchase-orders.js`

**ใน `selectCatalogItem()` — แทนการ set ค่า input, ให้ update display element แทน:**
```javascript
function selectCatalogItem(item) {
  const tierPrices = Array.isArray(item.tier_prices) ? item.tier_prices : [];

  currentCatalogItem = {
    id: item.id,
    code: item.code,
    name: item.name,
    unit: item.default_unit || 'ชิ้น',
    price: parseFloat(item.default_price || 0),
    catId: item.category_id || '',
    catName: item.category_name || '',
    tierPrices,
    requiresPreciousReceipt: item.requires_precious_receipt == 1,
  };

  // fill fields
  document.getElementById('itemName').value = item.name;
  document.getElementById('itemCatalogId').value = item.id;
  document.getElementById('itemUnit').value = currentCatalogItem.unit;

  // set category (เหมือนเดิม)
  // ...

  // rebuild tier buttons
  buildTierButtons(tierPrices);

  // auto-fill price → แสดงใน priceDisplay
  let price = 0;
  if (globalTier.level && tierPrices[globalTier.level - 1]?.price > 0) {
    price = parseFloat(tierPrices[globalTier.level - 1].price);
  } else {
    price = currentCatalogItem.price;
  }
  updatePriceDisplay(price);

  // reset weight
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemWeightDeduct').value = '0';
  updateItemTotal();
}

// ฟังก์ชันใหม่: update price display + hidden field (สำหรับส่ง payload)
function updatePriceDisplay(price) {
  const el = document.getElementById('itemPriceDisplay');
  if (price > 0) {
    el.textContent = formatCurrency(price);
    el.style.color = '#059669';
    el.style.fontWeight = '700';
  } else {
    el.textContent = '—';
    el.style.color = '#999';
  }
  // เก็บค่าไว้ใน hidden field (สำหรับ payload ตอน save)
  document.getElementById('itemUnitPrice').value = price.toFixed(2);
}

// แก้ updateItemTotal() ให้อ่านค่าราคาจาก display (ไม่ต้อง parse input)
function updateItemTotal() {
  const q = parseFloat(document.getElementById('itemQuantity').value || 0);
  const d = parseFloat(document.getElementById('itemWeightDeduct').value || 0);
  const net = Math.max(0, q - d);
  const priceEl = document.getElementById('itemPriceDisplay');
  const priceText = priceEl.textContent.replace(/[^0-9.]/g, '');
  const p = parseFloat(priceText) || 0;

  const totalEl = document.getElementById('itemTotalPreview');
  if (q > 0 && p > 0) {
    totalEl.textContent = formatCurrency(net * p);
    totalEl.style.color = '#059669';
    totalEl.style.fontWeight = '700';
  } else {
    totalEl.textContent = q === 0 ? 'กำหนดน้ำหนัก' : 'เลือกราคา (บิล)';
    totalEl.style.color = '#999';
  }
}
```

#### 1.3 อัปเดต `addItemToCart()` — อ่านราคาจาก hidden field

```javascript
function addItemToCart() {
  const name = document.getElementById('itemName').value.trim();
  const catalogId = document.getElementById('itemCatalogId').value;
  const catId = document.getElementById('itemCategoryId').value;
  const qty = parseFloat(document.getElementById('itemQuantity').value || 0);
  const deduct = parseFloat(document.getElementById('itemWeightDeduct').value || 0);
  const price = parseFloat(document.getElementById('itemUnitPrice').value || 0); // hidden field
  const unit = document.getElementById('itemUnit').value || 'กก.';

  // validation (เหมือนเดิม)
  // ...
}
```

#### 1.4 CSS เพิ่มเติม

**ไฟล์:** `code/base-pos/assets/css/components/purchase-orders.css`

```css
/* Auto-price display */
.auto-price-display {
  padding: 8px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  font-size: 15px;
  font-weight: 700;
  color: #059669;
  min-height: 20px;
  line-height: 1.5;
}

/* New row layout */
.po-field-row {
  display: flex;
  gap: 12px;
  align-items: flex-end;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.po-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.po-field label {
  font-size: 12px;
  font-weight: 600;
  color: #555;
}

.po-field-name { flex: 2; min-width: 200px; }
.po-field-unit { flex: 0 0 80px; }
.po-field-weight { flex: 1; min-width: 120px; }
.po-field-deduct { flex: 1; min-width: 120px; }
.po-field-price-display { flex: 0 0 130px; }
.po-field-total { flex: 0 0 130px; }
.po-field-photo { flex: 0 0 60px; }
.po-field-addbtn { flex: 0 0 90px; }

/* ปรับ container tier buttons */
#globalTierButtons {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
```

#### 1.5 อัปเดต tier button click — ใช้ `updatePriceDisplay()` แทนการ set input

```javascript
function onTierClick(btn, level) {
  setTierActive(btn, level);
  if (currentCatalogItem) {
    const tierPrices = currentCatalogItem.tierPrices;
    if (tierPrices && tierPrices[level - 1]?.price > 0) {
      updatePriceDisplay(parseFloat(tierPrices[level - 1].price));
      updateItemTotal();
    }
  }
}
```

### Verify Form Simplification:
```
[ ] เลือกสินค้าจาก catalog → ราคาแสดงใน display element (ไม่ใช่ input)
[ ] ช่องราคาต่อหน่วยไม่มีให้กรอก — แสดงเป็นตัวเลขอ่านอย่างเดียว
[ ] กดเปลี่ยน tier (บิล1/2/3) → ราคาอัปเดตทันที
[ ] กรอกน้ำหนัก + หักน้ำหนัก → ยอดรวมคำนวณอัตโนมัติ
[ ] กด [+ เพิ่ม] → เข้า Cart → ราคาถูกต้อง
[ ] Cart table แสดงราคาถูกต้อง
[ ] Save PO → API รับค่า price ถูกต้อง (จาก hidden field)
[ ] Mobile (320px width) → form ไม่พัง, ยังใช้งานได้
```

---

## Task 2: FIFO Costing End-to-End Test (2 ชม.)

### 2.1 สร้าง test scenario

ใช้ API โดยตรง (curl หรือ test script) หรือ manual ผ่าน UI:

```
Scenario: รับซื้อทองแดง 3 ครั้ง → ขาย Lot 1 ครั้ง → ตรวจสอบ consumed_qty + profit

Step 1: PO #1 — ทองแดง 100 กก. ราคา 150 บาท/กก. → total 15,000
Step 2: PO #2 — ทองแดง 50 กก. ราคา 160 บาท/กก. → total 8,000
Step 3: PO #3 — ทองแดง 200 กก. ราคา 155 บาท/กก. → total 31,000
Step 4: ขาย Lot — ทองแดง 120 กก. ราคา 180 บาท/กก. → subtotal 21,600
Step 5: ตรวจสอบ:
        - consumed_qty PO #1 = 100 (FIFO: ของเก่าที่สุดถูกตัดก่อน)
        - consumed_qty PO #2 = 20 (เหลืออีก 20 จาก lot)
        - consumed_qty PO #3 = 0 (ยังไม่โดนตัด)
        - total_cost = (100 × 150) + (20 × 160) = 15,000 + 3,200 = 18,200
        - profit = 21,600 - 18,200 = 3,400
```

### 2.2 Check consumed_qty atomic safety

```
Step 6: ส่ง request confirm lot พร้อมกัน 2 ครั้ง (parallel)
        → consumed_qty ต้องถูกต้อง (ไม่นับซ้ำ)
        → TOCTOU check: SELECT ... FOR UPDATE ทำงาน
```

### 2.3 Check cancel → restore

```
Step 7: Cancel Lot → consumed_qty ต้องกลับเป็น 0
        → stock ต้องกลับมาเท่าเดิม
```

### 2.4 Verify report

```
Step 8: ตรวจสอบ financial summary
        → purchase-by-category แสดงยอดรับซื้อทองแดงรวม = 54,000
        → sale-lot-report แสดง lot พร้อม profit = 3,400
```

### Verify:
```
[ ] consumed_qty ถูกต้องตาม FIFO logic
[ ] atomic UPDATE ไม่มี race condition
[ ] Cancel → restore consumed_qty = 0
[ ] Financial summary → purchase total ตรง
[ ] Sale Lot report → profit ตรง
```

---

## Task 3: Employee Module (G4) Smoke Test (1 ชม.)

### 3.1 CRUD Employee

```
Step 1: สร้างพนักงาน (POST /employees)
        → full_name: "ทดสอบ พนักงานA"
        → branch_id: 1
        → salary: 15000
        → social_security_rate: 5
        → social_security_number: "1234567890"
        → EXPECTED: status: success, id > 0

Step 2: แก้ไขพนักงาน (PUT /employees/employee?id=X)
        → salary: 18000
        → EXPECTED: status: success

Step 3: ดูรายการพนักงาน (GET /employees)
        → EXPECTED: มีรายการ "ทดสอบ พนักงานA"
        → EXPECTED: filter by branch_id ทำงาน
        → EXPECTED: search by name ทำงาน

Step 4: ลบพนักงาน (DELETE /employees/employee?id=X)
        → EXPECTED: status: success
```

### 3.2 Salary → BusinessExpenses

```
Step 5: จ่ายเงินเดือน (POST /employees/salary-expense)
        → employee_id: X
        → salary_date: "2026-07-01"
        → amount: 14250 (15,000 - 5% SSO)
        → EXPECTED: status: success, expense_id > 0

Step 6: ตรวจสอบ BusinessExpenses
        → GET /financial/expenses?year=2026&month=7
        → EXPECTED: มี record "เงินเดือน: ทดสอบ พนักงานA" จำนวน 14,250.00
        → category = "salary"

Step 7: SSO expense (POST /employees/sso-expense)
        → employee_id: X
        → salary_date: "2026-07-01"
        → amount: 750 (5% of 15,000)
        → EXPECTED: status: success

Step 8: ตรวจสอบ BusinessExpenses อีกครั้ง
        → EXPECTED: มี record "ประกันสังคม (นายจ้าง): ทดสอบ พนักงานA"
        → category = "social_security"
```

### 3.3 UI Test (Manual)

```
Step 9: เปิดหน้า employees.html ใน browser
        → ตารางแสดงพนักงาน
        → filter branch, filter status ทำงาน
        → search โดยชื่อ/เบอร์/เลขบัตร ทำงาน

Step 10: กด "เพิ่มพนักงาน" → modal → กรอกข้อมูล → save
         → กลับมาที่ตาราง → เห็นพนักงานใหม่

Step 11: กด "จ่ายเงินเดือน" → modal → จำนวนเงิน auto-fill → save
         → ไปหน้า "ค่าใช้จ่ายร้าน" → ดูรายการเงินเดือน
```

### Verify:
```
[ ] CRUD Employee ผ่าน (create, read, update, delete)
[ ] Salary → BusinessExpenses อัตโนมัติ
[ ] SSO → BusinessExpenses อัตโนมัติ
[ ] filter, search ทำงาน
[ ] UI modal ทำงาน
```

---

## Task 4: Sale Lot Transport Cost Verify (1 ชม.)

### 4.1 ตรวจสอบ migration 039

```bash
docker compose exec db mysql -u root -p${MYSQL_ROOT_PASSWORD} pos_system \
  -e "DESCRIBE sale_lots;" | grep transport_cost
# EXPECTED: transport_cost decimal(12,2) NO 0.00
```

### 4.2 ทดสอบสร้าง Lot พร้อมค่าขนส่ง

```
Step 1: ขาย Lot พร้อม transport_cost = 500
        → POST /sale-lots
        → { ...items..., transport_cost: 500 }
        → EXPECTED: success

Step 2: ดู Sale Lot รายละเอียด
        → GET /sale-lots/sale-lot?id=X
        → EXPECTED: transport_cost = 500

Step 3: ตรวจสอบ net_profit
        → total_amount: 21,600
        → total_cost: 18,200
        → transport_cost: 500
        → กำไรขั้นต้น (profit, generated column): 3,400
        → กำไรสุทธิ (คำนวณ PHP, ไม่ใช่ DB): 2,900
```

### 4.3 ตรวจสอบ UI

```
Step 4: เปิดหน้า sale-lots.html
        → column ขนส่งแสดง 500.00
        → column กำไรสุทธิแสดง 2,900.00
        → filter ยังทำงานปกติ
```

### Verify:
```
[ ] transport_cost บันทึกและแสดงใน API response
[ ] UI แสดง transport_cost + net profit
[ ] transport_cost = 0.00 default (Lot เก่าไม่เสียหาย)
[ ] profit (gross) = total_amount - total_cost (generated column ไม่เปลี่ยน)
[ ] net_profit = total_amount - total_cost - transport_cost (PHP computed)
```

---

## ✅ Gate Criteria — ส่งต่องาน (Handoff to Day 4)

```
[ ] 1. Purchase form simplification
     → ราคา auto-fill, ไม่มีช่องให้กรอกราคา
     → พนักงานกรอกแค่: น้ำหนัก + หักน้ำหนัก
     → Tier buttons + price display ทำงานสอดคล้อง

[ ] 2. FIFO costing verified
     → consumed_qty ถูกต้องตาม FIFO logic
     → atomic UPDATE ไม่มี race condition
     → cancel → restore ถูกต้อง

[ ] 3. Employee module (G4) smoke test
     → CRUD ผ่าน
     → Salary → BusinessExpenses เชื่อม
     → SSO → BusinessExpenses เชื่อม

[ ] 4. Transport cost (G5) verified
     → migration 039 มี transport_cost column
     → API สร้าง Lot + transport_cost ได้
     → UI แสดง transport_cost + net profit

[ ] 5. No regression
     → PO เดิมที่มีอยู่แล้วยังเปิดดูได้ (ไม่เสียหาย)
     → Lot เดิมที่มีอยู่แล้วยังแสดงผลปกติ
     → Dashboard ยังโหลดได้
     → Report ยังทำงาน
```

**FAIL 1 ข้อ = แก้ก่อนส่งต่องาน**

---

## ⛔ สิ่งที่ PHP Full-stack ห้ามทำ

| ข้อห้าม | เพราะ |
|---------|-------|
| ❌ ห้ามแก้ infrastructure (docker, nginx, .env) | ส่ง DevOps |
| ❌ ห้ามแก้ auth logic (JWT, login, rate limit) | ส่ง Backend Security |
| ❌ ห้ามลบ migration เดิม | idempotent, ไม่ต้องลบ |
| ❌ ห้ามเปลี่ยน database connection, charset | production stability |
| ❌ ห้ามเพิ่ม library/dependency | PHP Vanilla = ไม่มี Composer |
| ❌ ห้าม deploy โดยไม่ merge กับ Day 2 branch | conflict management |

---

## 📞 ถ้าติดปัญหา

1. **ลองแก้เอง 30 นาที** — ถ้าไม่หลุด →
2. **หยุด** — อย่าทำอะไรต่อ →
3. **แจ้ง Architect พร้อม:** error message, step ที่ fail, docker compose logs

---

## 🎯 Definition of Done สำหรับ PHP Full-stack (Day 3)

> **"Business Flow หลัก (รับซื้อ → ขาย Lot) ใช้งานได้เรียบง่าย, FIFO ถูกต้อง, Employee module + Transport cost ครบ, ไม่มี regression — ส่งต่องานให้ UX/Frontend Team ได้"**

---

*Document version: 1.0 | 2026-07-03 | System Architect*
