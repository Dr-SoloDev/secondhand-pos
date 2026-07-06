> **⚠️ Timeline ถูก supersede แล้ว** — ดูแผนใหม่: [`docs/OPS-DEPLOYMENT-PLAN.md`](OPS-DEPLOYMENT-PLAN.md)
> สถานะ: **NO-GO** จนกว่า 3 blockers จะปิด (G3 SQL, admin/admin, HTTPS)

# 📋 JOB ORDER: UX/Frontend Engineer — Day 4

**ส่งถึง:** UX/Frontend Engineer  
**จาก:** System Architect  
**กำหนดส่ง:** วันสิ้นสุด Day 4 (23:59 น.)  
**รับต่อจาก:** PHP Full-stack Engineer (Day 3)  
**Handoff ให้:** ทีมร่วม — Launch Day (Day 5)

---

## Pre-condition (ต้องได้จาก Day 3 ก่อนเริ่ม)

```
□ Purchase form: auto-fill price, ซ่อน price input, แค่ นน.+หัก
□ FIFO costing: consumed_qty atomic, cancel→restore
□ Employee module (G4): CRUD + salary→expenses + SSO
□ Transport cost (G5): column + display + net profit
□ No regression: PO/Lot เก่ายังดูได้, Dashboard ยังโหลด
```

**ถ้าข้อไหนยังไม่ผ่าน → หยุด, แจ้ง Architect, อย่าเริ่ม**

---

## ภารกิจหลัก

ทำให้ระบบดู **Professional, ใช้งานง่าย, ไม่มี Dead-end** — โฟกัสที่หน้างานที่พนักงานใช้จริง (Tablet-first) + การรับมือเมื่อเกิด error

| Priority | Task | เวลา | ความเสี่ยงถ้าไม่ทำ |
|----------|------|------|--------------------|
| P0 | Tablet responsive polish | 3 ชม. | พนักงานใช้ tablet แล้ว UI เละ |
| P0 | 404 / 500 error pages | 1 ชม. | user เจอ white screen ไม่รู้ทำยังไง |
| P1 | Loading states + skeleton | 2 ชม. | user คิดว่าระบบค้าง |
| P1 | Remaining page polish | 3 ชม. | เหลือหน้าเก่าที่ยังไม่แตะ UX |
| P2 | Offline / network warning | 1 ชม. | เน็ตหลุดแล้ว save ไม่รู้ตัว |

---

## Task 1: Tablet Responsive Polish (3 ชม.)

### หลักการ

หน้ารับซื้อของ (`purchase-orders.html`) คือหน้าที่พนักงานใช้ **ทั้งวัน** — และใช้บน **tablet (iPad/Android tablet)** เป็นหลัก ต้องตรวจสอบทุก breakpoint

### สิ่งที่ต้องทำ

#### 1.1 Audit ทุกหน้าหลักบน 768px และ 576px

เปิด browser DevTools → resize เป็น 768px × 1024px (iPad) และ 576px × 812px (iPhone) — ถ่าย screenshot ทุกหน้าต่อไปนี้:

| หน้า | ต้องตรวจ |
|------|----------|
| `purchase-orders.html` | Add-row, tier buttons, cart table, seller card, modal |
| `sale-lots.html` | Item table, create modal, lot list |
| `dashboard.html` | Stat cards, charts |
| `inventory.html` | Category stock grid, catalog table |
| `sellers.html` | Search, table, modal |
| `employees.html` | Table, modal |
| `expenses.html` | Form, table |
| `financial-summary.html` | Cards, charts |
| `reports.html` | Filters, tables |
| `branches.html` | Table, modal |
| `users.html` | Table, modal |
| `settings.html` | Forms |

#### 1.2 ปัญหาที่พบบ่อย + วิธีแก้

| ปัญหา | วิธีแก้ |
|-------|--------|
| Table กว้างเกิน → overflow แนวนอน | เพิ่ม `.table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }` |
| Modal ขนาดเท่า desktop → ต้อง scroll แนวตอน | `.modal-content { max-width: 95%; margin: 10px auto; }` ที่ breakpoint |
| ปุ่มกดยากเกิน (เล็กกว่า 44px) | `min-height: 44px; min-width: 44px;` สำหรับ interactive elements |
| form-row 2 columns → ซ้อนกัน | `flex-wrap: wrap;` และ `.form-col { min-width: 100% }` ที่ 576px |
| Sidebar กินเนื้อที่太多 → ซ่อน | เพิ่ม hamburger menu toggle ที่ breakpoint (หรือคง sidebar ไว้แบบ mini) |

#### 1.3 CSS Amendments

**ไฟล์:** `code/base-pos/assets/css/styles.css`

```css
/* ===== Tablet & Mobile Responsive ===== */

/* iPad / Tablet */
@media (max-width: 768px) {
  .app-container {
    grid-template-columns: 1fr;
  }

  .sidebar {
    width: 100%;
    height: auto;
    position: relative;
    overflow-x: auto;
  }

  .sidebar-menu {
    display: flex;
    flex-wrap: nowrap;
    overflow-x: auto;
    gap: 4px;
    padding: 4px;
  }

  .sidebar-menu li {
    white-space: nowrap;
  }

  .sidebar-menu a {
    padding: 8px 12px;
    font-size: 13px;
  }

  .sidebar-menu span {
    display: inline;
  }

  .content-area {
    margin: 0;
    padding: 12px;
  }

  .page-header {
    flex-direction: column;
    gap: 8px;
  }

  .card-header {
    flex-direction: column;
    gap: 8px;
  }

  .table-container {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  .modal-content {
    max-width: 95%;
    margin: 20px auto;
  }

  .form-row {
    flex-direction: column;
  }

  .form-col {
    min-width: 100%;
  }
}

/* Phone */
@media (max-width: 576px) {
  .sidebar-menu {
    display: none; /* ถ้าไม่มี hamburger → อย่างน้อยซ่อน |
                      แต่ควรเพิ่ม toggle ก่อน production */
  }

  .topbar {
    flex-direction: column;
    gap: 8px;
  }

  .user-dropdown {
    align-self: flex-end;
  }

  button, .btn {
    min-height: 44px;
    min-width: 44px;
    font-size: 14px;
  }
}
```

#### 1.4 Hamburger Menu (ถ้าเวลาเหลือ)

เพิ่มปุ่ม toggle sidebar สำหรับ mobile:
```html
<button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>
```

```javascript
function toggleSidebar() {
  document.querySelector('.sidebar').classList.toggle('show');
}
```

**Verify:**
```
[ ] 768px → ทุกหน้า: table มี scroll, form-row ไม่ซ้อนทับ, modal ไม่หลุดจอ
[ ] 576px → ทุกหน้า: ปุ่มกดได้ (44px), ตัวหนังสืออ่านออก, ไม่มี overflow แนวตั้ง
[ ] Modal → ปุ่มปิด modal แตะได้, form inputs ไม่โดน keyboard บัง
[ ] Sidebar → กินพื้นที่ไม่เกิน 30% หรือซ่อน/ย่อ
```

---

## Task 2: Error Pages (1 ชม.)

### 2.1 404 — Page Not Found

**ไฟล์:** `code/base-pos/admin/404.html`

```html
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 - ไม่พบหน้า | รักษ์สะอาดรีไซเคิล</title>
  <link rel="stylesheet" href="../assets/css/main.css">
  <style>
    .error-page { display: flex; align-items: center; justify-content: center; min-height: 100vh; text-align: center; padding: 20px; background: #f8fafc; }
    .error-box { max-width: 400px; }
    .error-code { font-size: 72px; font-weight: 800; color: #d1d5db; line-height: 1; }
    .error-title { font-size: 20px; font-weight: 700; margin: 16px 0 8px; color: #1f2937; }
    .error-message { font-size: 14px; color: #6b7280; margin-bottom: 24px; }
    .error-btn { padding: 10px 24px; background: #1d4ed8; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
  </style>
</head>
<body>
  <div class="error-page">
    <div class="error-box">
      <div class="error-code">404</div>
      <div class="error-title">ไม่พบหน้าที่คุณต้องการ</div>
      <div class="error-message">
        หน้าที่คุณกำลังมองหาอาจถูกลบ เปลี่ยนชื่อ หรือไม่มีอยู่ในระบบ<br>
        กรุณาตรวจสอบลิงก์อีกครั้ง
      </div>
      <a href="index.html" class="error-btn">← กลับหน้าหลัก</a>
    </div>
  </div>
</body>
</html>
```

### 2.2 500 — Server Error

สร้าง `code/base-pos/500.html` (ดีไซน์คล้ายกัน, ต่างที่ข้อความ):

```
500: ระบบมีข้อผิดพลาดชั่วคราว
กรุณาลองใหม่ในอีกสักครู่ หรือติดต่อผู้ดูแลระบบ
ปุ่ม: "ลองใหม่" (reload) + "กลับหน้าหลัก"
```

### 2.3 401 — Session Expired

**ปรับหน้า login (`code/base-pos/index.html`):** ถ้ามี query param `?expired=1`:
```javascript
// ใน login.js
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('expired') === '1') {
  showNotification('เซสชั่นหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง', 'warning');
  // หรือแสดง alert สวยๆ ในหน้า login
}
```

### 2.4 Web Server Config

**ไฟล์:** `code/docker/apache-config.conf`

```apache
# Custom error pages
ErrorDocument 404 /admin/404.html
ErrorDocument 500 /500.html

# ถ้า request ไม่มี extension (เช่น /purchase-orders) → 404
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /admin/404.html [L]
```

**Verify:**
```
[ ] curl https://domain.com/nonexistent-page → 404.html (ไม่ใช่ Apache default)
[ ] ลอง POST ข้อมูลไม่ถูกต้อง → 500 error → 500.html (หรือ JSON response, แล้วแต่การเรียก)
[ ] login?expired=1 → แสดงข้อความ "เซสชั่นหมดอายุ"
```

---

## Task 3: Loading States + Skeleton (2 ชม.)

### 3.1 Skeleton Loading สำหรับ Data Table

**CSS:**
```css
/* Skeleton loading */
@keyframes shimmer {
  0% { background-position: -200px 0; }
  100% { background-position: calc(200px + 100%) 0; }
}

.skeleton-row {
  height: 24px;
  background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
  background-size: 200px 100%;
  animation: shimmer 1.5s ease-in-out infinite;
  border-radius: 4px;
  margin-bottom: 8px;
}

.skeleton-row:last-child { width: 60%; }
```

### 3.2 API Loading State

**ใน `common.js` `apiRequest()`:** (หรือในแต่ละหน้าที่เรียก API)

ถ้า API call ใช้เวลานาน (> 2 วินาที) → แสดง loading overlay:

```javascript
// เรียกก่อน fetch
function showLoading(targetEl) {
  const el = document.getElementById(targetEl);
  if (!el) return;
  el.dataset.originalContent = el.dataset.originalContent || el.innerHTML;
  el.innerHTML = '<div style="text-align:center;padding:20px"><div class="spinner"></div><div style="margin-top:8px;color:#888;font-size:13px">กำลังโหลด...</div></div>';
}

function hideLoading(targetEl) {
  const el = document.getElementById(targetEl);
  if (!el || !el.dataset.originalContent) return;
  el.innerHTML = el.dataset.originalContent;
  delete el.dataset.originalContent;
}
```

### 3.3 Spinner Component

```css
.spinner {
  width: 32px; height: 32px;
  border: 3px solid #e5e7eb;
  border-top-color: #1d4ed8;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  margin: 0 auto;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
```

### 3.4 Save/POST Loading State (ส่วนใหญ่มีแล้ว)

ตรวจสอบว่าทุกหน้าใช้ pattern เดียวกัน:
```javascript
// Disable + loading text
const btn = document.getElementById('saveBtn');
btn.disabled = true;
btn.textContent = 'กำลังบันทึก...';

// ... API call ...

btn.disabled = false;
btn.textContent = 'บันทึก'; // หรือ text เดิม
```

**Verify:**
```
[ ] ทุก Data table → ขณะโหลดมี skeleton หรือ spinner
[ ] Save button → disable + "กำลังบันทึก..." + enable กลับ
[ ] Modal → ขณะโหลดมี feedback
[ ] API error → notification (ไม่ใช่ console log)
```

---

## Task 4: Remaining Page Polish (3 ชม.)

ตรวจสอบและปรับ UX หน้าเหล่านี้ (ที่เหลือจาก UX pass รอบก่อน):

### 4.1 Inventory (`inventory.html`)

```
[ ] ตาราง category stock → grid ไม่เหลื่อมที่ 768px
[ ] modal เพิ่ม/แก้ไข catalog item → ราคา tier auto-fill
[ ] product search → debounce (มีอยู่แล้ว)
[ ] Stock alert ตั้งค่า → feedback เมื่อ save
```

### 4.2 Stock Transfers (`stock-transfers.html`)

```
[ ] form สร้าง transfer → เลือก from≠to branch, category, weight
[ ] List → filter by status ทำงาน
[ ] confirm modal → "ยืนยันการโอน?"
[ ] cancel → "ยกเลิก?"
```

### 4.3 Expenses (`expenses.html`)

```
[ ] Add form → branch, date, category, amount, note
[ ] List → filter โดย month/year/branch
[ ] Total → สีแดง, format currency
[ ] Delete → confirm dialog
```

### 4.4 Financial Summary (`financial-summary.html`)

```
[ ] Stat cards → number format large font
[ ] purchase-by-category chart → labels ไม่ทับกัน
[ ] lot-revenues → sort by date
[ ] Export CSV → ทำงาน (ถ้ามี)
```

### 4.5 Reports (`reports.html`)

```
[ ] Filters → date range มี default (เดือนนี้)
[ ] Table → pagination หรือ scroll
[ ] Chart ไม่พังที่ 768px
[ ] Export → ทำงานหรือมี placeholder
```

### 4.6 Users / Branches / Settings

```
[ ] Users → CRUD, role filter, activity log
[ ] Branches → ตาราง, edit modal, status badge
[ ] Settings → store info, system config, backup
```

### 4.7 Consistency Check (ทั่วทั้งระบบ)

```
[ ] ปุ่มยกเลิก modal ทุกตัว → "ยกเลิก" (consistent wording)
[ ] Notification → สีเขียว=success, แดง=error, เหลือง=warning
[ ] Header ทุกหน้า → h1 + breadcrumb หรือ description
[ ] Sidebar → active page เด่น
[ ] table thead → background สีเดียวกับ theme
[ ] Badge → success=เขียว, warning=เหลือง, danger=แดง, secondary=เทา
```

---

## Task 5: Offline / Network Warning (1 ชม.)

### 5.1 Network Status Monitoring

**ไฟล์:** `code/base-pos/assets/js/common.js`

```javascript
// === Online/Offline Detection ===
(function() {
  const banner = document.createElement('div');
  banner.id = 'offlineBanner';
  banner.style.cssText = 'display:none;position:fixed;top:0;left:0;right:0;z-index:99999;background:#dc2626;color:#fff;text-align:center;padding:10px;font-size:14px;font-weight:600;font-family:sans-serif;';
  banner.textContent = '⚠️ ไม่มีการเชื่อมต่ออินเทอร์เน็ต — ข้อมูลอาจไม่ถูกบันทึก';
  document.body.prepend(banner);

  window.addEventListener('online', () => {
    banner.style.display = 'none';
  });

  window.addEventListener('offline', () => {
    banner.style.display = 'block';
  });

  // Check initial state
  if (!navigator.onLine) {
    banner.style.display = 'block';
  }
})();
```

### 5.2 API Timeout Handling

ใน `apiRequest` ให้เพิ่ม timeout (15 วินาที):
```javascript
async function apiRequest(endpoint, method = 'GET', data = null) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 15000); // 15s timeout

  try {
    const res = await fetch(`${apiPath}/${endpoint}`, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: data ? JSON.stringify(data) : undefined,
      signal: controller.signal,
    });
    clearTimeout(timeoutId);
    // ... continue processing ...
  } catch (err) {
    clearTimeout(timeoutId);
    if (err.name === 'AbortError') {
      showNotification('การเชื่อมต่อใช้เวลานานเกินไป กรุณาลองใหม่', 'error');
      return { status: 'error', message: 'Request timeout' };
    }
    // ... existing error handling ...
  }
}
```

**Verify:**
```
[ ] ปิด WiFi → แถบแดง "ไม่มีการเชื่อมต่อ" แสดง
[ ] เปิด WiFi → แถบหาย
[ ] ปิด API server → request timeout → notification "การเชื่อมต่อใช้เวลานานเกินไป"
```

---

## ✅ Gate Criteria — ส่งต่องาน (Handoff to Day 5 — Launch)

```
[ ] 1. Tablet responsive (768px)
     → purchase-orders, sale-lots, inventory → ใช้ได้
     → table overflow-x auto, modal ไม่หลุด
     → ปุ่มกดง่าย (min 44px)

[ ] 2. Error pages
     → 404.html, 500.html
     → 401 → login?expired=1 → แสดงข้อความ

[ ] 3. Loading states
     → skeleton / spinner ขณะโหลด
     → save button → disable + "กำลังบันทึก..."
     → API error → notification (ไม่ใช่ console.log)

[ ] 4. Remaining pages polished
     → inventory, stock-transfers, expenses, financial-summary,
       reports, users, branches, settings → UX ไม่มี broken elements

[ ] 5. Offline detection
     → offline = red banner
     → API timeout = notification

[ ] 6. Consistency
     → modal buttons → "ยกเลิก" ทุกหน้า
     → notification สีถูกต้อง
     → sidebar active page เด่น
     → Badge สีถูกต้อง
```

**FAIL 1 ข้อก่อนส่ง Day 5 — Launch Day**

---

## ⛔ สิ่งที่ UX/Frontend ห้ามทำ

| ข้อห้าม | เพราะ |
|---------|-------|
| ❌ ห้ามแก้ business logic (PHP) | ส่ง PHP Full-stack |
| ❌ ห้ามแก้ DB schema / migrations | ไม่เกี่ยวข้อง |
| ❌ ห้ามเพิ่ม library (jQuery, React, Bootstrap) | Vanilla JS เท่านั้น |
| ❌ ห้ามลบ/comment ฟังก์ชันที่มีอยู่ | regression risk |
| ❌ ห้ามแก้ API contract (response format) | backend dependent |
| ❌ ห้าม deploy ไป production | Day 5 เท่านั้น |

---

## 📞 ถ้าติดปัญหา

1. **ลองแก้เอง 30 นาที** — ถ้าไม่หลุด →
2. **หยุด** — ถ้าเป็น blocking issue →
3. **แจ้ง Architect พร้อม:** screenshot, browser, device

---

## 🎯 Definition of Done สำหรับ UX/Frontend (Day 4)

> **"ระบบดู Professional, ใช้บน tablet ได้, ไม่มี Dead-end หน้าจอ, error handling ครบ, ทุกหน้าสวย consistent — ส่งต่องานให้ Launch Day Team ได้"**

---

*Document version: 1.0 | 2026-07-03 | System Architect*
