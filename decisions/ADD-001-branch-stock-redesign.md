# ADD-001: Branch Stock Redesign

| Metadata | Value |
|:---------|:------|
| **Status** | **Proposed** |
| **Author** | พี่ทรงศักดิ์ (Architect) |
| **Approved by** | CEO (เทอโบ) |
| **Date** | 2026-07-18 |
| **Depends on** | Migrations 001–050 (all current) |

---

## 1. Context

### 1.1 ปัญหาที่เจอ

**Problem 1: `categories.stock_kg` เป็น Global Aggregate**
- ปัจจุบัน `categories.stock_kg` เก็บผลรวม stock ต่อ category_id *โดยรวมทุกสาขา*
- แต่ละสาขาควรมี stock ของตัวเอง per-item
- การ query stock per-branch ใน `InventoryController::getCategories()` ต้อง JOIN `purchase_order_items` ทุกครั้ง → performance ต่ำ

**Problem 2: ไม่มีการ Track Stock ตาม `item_name`**
- `categories.stock_kg` บวกเลขรวมหมด ไม่รู้ว่า 50 kg ที่มีเป็น "ทองแดงเบอร์ 1" หรือ "ทองแดงเบอร์ 2"
- `SaleLot::calculateFifoCost()` หาต้นทุน FIFO จาก `category_id` อย่างเดียว → อาจ match item_name ผิดประเภท

**Problem 3: Stock Transfer ทำให้ Item Identity หาย**
- `StockTransfer::confirm()` สร้าง PO ที่ปลายทางด้วย `item_name = "โอนสต็อก (ST: ...)"` → ข้อมูล original item_name หาย
- ทำให้รายงาน inventory ไม่รู้ว่าปลายทางมีของอะไรจริง

**Problem 4: Reports Query จาก `products` Table (ผิด)**
- `ReportService::getInventoryReport()` ยัง query จาก `products` table (ระบบ retail เดิม)
- แต่ธุรกิจรับซื้อของเก่าใช้ `purchase_order_items` เป็นหลัก
- `ReportService::getDashboardStats()` นับ `total_sellers` โดยไม่ filter branch

**Problem 5: Stock Alerts ไม่แยกสาขา**
- `InventoryController::getStockAlerts()` query `categories` โดยตรง → global ตลอด
- ไม่รู้ว่าสาขาไหนมี stock ต่ำ

**Problem 6: Dashboard `total_sellers` Global**
- `ReportService::getDashboardStats()` นับ `total_sellers` โดยไม่ filter branch

### 1.2 Data Flow ปัจจุบัน

```
PO Create:
  INSERT purchase_order → INSERT purchase_order_items
  → UPDATE categories SET stock_kg = stock_kg + qty (GLOBAL — ❌)

PO Cancel:
  UPDATE categories SET stock_kg = GREATEST(0, stock_kg - qty) (GLOBAL — ❌)

SaleLot Confirm:
  SELECT purchase_order_items WHERE category_id = X ORDER BY created_at ASC
  → UPDATE consumed_qty (category_id only — ❌ ไม่ match item_name)
  → UPDATE categories SET stock_kg = stock_kg - qty (GLOBAL — ❌)

Stock Transfer:
  SELECT purchase_order_items WHERE category_id = X → UPDATE consumed_qty
  → INSERT purchase_order_items (item_name = "โอนสต็อก..." — ❌ identity loss)

Inventory Report:
  SELECT FROM products (❌ WRONG TABLE)

Stock Alerts:
  SELECT FROM categories WHERE stock_kg <= threshold (❌ GLOBAL)
```

---

## 2. Decision

### สร้างตาราง `branch_stock` เป็น **Source of Truth** สำหรับสต็อกทั้งหมด

```sql
CREATE TABLE branch_stock (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    branch_id   INT NOT NULL,
    category_id INT NOT NULL,
    item_name   VARCHAR(200) NOT NULL,
    stock_kg    DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    unit_price  DECIMAL(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'weighted avg cost per kg',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_branch_category_item (branch_id, category_id, item_name),
    FOREIGN KEY (branch_id)   REFERENCES branches(id)   ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES categories(id)  ON DELETE RESTRICT,
    INDEX idx_branch_category (branch_id, category_id),
    INDEX idx_item_name (item_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-branch per-item stock tracking';
```

**Rationale:**
- `UNIQUE(branch_id, category_id, item_name)` → ป้องกัน duplicate, UPSERT ได้ง่าย
- `unit_price` = weighted average cost (คำนวณจาก purchase_order_items)
- `stock_kg` = สต็อกคงเหลือ实时 (ไม่ต้อง SUM purchase_order_items ทุกครั้ง)
- `last_updated` = รู้ว่าเมื่อไหร่มีการเปลี่ยนแปลงล่าสุด

### สิ่งที่เปลี่ยนไป

| ก่อน | หลัง |
|:-----|:-----|
| `categories.stock_kg` = SOURCE OF TRUTH | `branch_stock.stock_kg` = SOURCE OF TRUTH |
| `categories.stock_kg` global per-category | `branch_stock` per (branch, category, item) |
| FIFO query JOIN purchase_order_items ทุกครั้ง | branch_stock ให้ stock แบบ real-time |
| Reports query จาก `products` → ผิด schema | Reports query จาก `branch_stock` + `purchase_order_items` |

**`categories.stock_kg` จะยังคงอยู่** (เพื่อ backward compatibility) แต่จะไม่ใช้อีกต่อไป — หรือจะลบทีหลังเมื่อมั่นใจว่าทุกอย่าง stable

---

## 3. Consequences

### ✅ ข้อดี
1. **Query stock per-branch per-item ได้ทันที** — ไม่ต้อง JOIN purchase_order_items
2. **FIFO ถูกต้อง** — match ทั้ง category_id + item_name + branch_id
3. **Stock Transfer preserve item identity** — โอน item_name + cost ไปให้สาขาปลายทาง
4. **Report ถูกต้อง** — branch-aware, item-aware
5. **Alert per-branch** — stock-alerts รู้ว่า branch ไหนขาดอะไร
6. **Dashboard ถูกต้อง** — metrics per-branch

### ⚠️ ข้อเสีย / ความเสี่ยง
1. **Data migration ต้องระวัง** — populate branch_stock จาก purchase_order_items ที่มี consumed_qty ต่างๆ
2. **Race condition** — UPSERT branch_stock ใน transaction ต้องใช้ `FOR UPDATE` หรือ `SELECT ... FOR UPDATE` ก่อน
3. **consistency** — branch_stock กับ consumed_qty ใน purchase_order_items ต้อง sync กันเสมอ
4. **Migration effort** — ~5 วันสำหรับ P0, ~2.5 วันสำหรับ P1

### 📊 Effort Breakdown

| Sprint | Items | Effort |
|:-------|:------|:-------|
| **Sprint A** | branch_stock table, PO flow, SaleLot FIFO, Stock Transfer | ~5 วัน |
| **Sprint B** | Reports, Dashboard, Stock Alerts | ~2.5 วัน |
| **Sprint C** | UI polish, Optimization | ~2.5 วัน |
| **Total** | | **~10 วัน** |

---

## 4. Technical Design

### 4.1 New Data Model

```
┌─────────────────────────────────┐
│          branch_stock           │  ← NEW — Source of Truth
├─────────────────────────────────┤
│ id (PK)                         │
│ branch_id (FK → branches)       │
│ category_id (FK → categories)   │
│ item_name          VARCHAR(200) │
│ stock_kg           DECIMAL(12,3)│
│ unit_price         DECIMAL(12,4)│ ← weighted avg cost
│ last_updated       TIMESTAMP    │
│ UNIQUE(branch_id, category_id, item_name) │
└─────────────────────────────────┘
```

### 4.2 Migration SQL

```sql
-- ============================================================
-- Migration 051: Create branch_stock table
-- ============================================================

-- Step 1: Create table
CREATE TABLE IF NOT EXISTS branch_stock (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    branch_id   INT NOT NULL,
    category_id INT NOT NULL,
    item_name   VARCHAR(200) NOT NULL,
    stock_kg    DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    unit_price  DECIMAL(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'weighted average cost per kg',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_branch_category_item (branch_id, category_id, item_name),
    FOREIGN KEY (branch_id)   REFERENCES branches(id)   ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES categories(id)  ON DELETE RESTRICT,
    INDEX idx_branch_category (branch_id, category_id),
    INDEX idx_item_name (item_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-branch per-item stock tracking';

-- Step 2: Populate from existing purchase_order_items
-- stock = SUM(quantity - weight_deduction - consumed_qty) per (branch_id, category_id, item_name)
-- unit_price = weighted average of unconsumed stock
INSERT INTO branch_stock (branch_id, category_id, item_name, stock_kg, unit_price)
SELECT
    po.branch_id,
    poi.category_id,
    poi.item_name,
    ROUND(SUM(poi.quantity - poi.weight_deduction - poi.consumed_qty), 3) AS stock_kg,
    CASE
        WHEN SUM(poi.quantity - poi.weight_deduction - poi.consumed_qty) > 0
        THEN ROUND(
            SUM((poi.quantity - poi.weight_deduction - poi.consumed_qty) * poi.unit_price)
            / SUM(poi.quantity - poi.weight_deduction - poi.consumed_qty),
            4
        )
        ELSE 0
    END AS unit_price
FROM purchase_order_items poi
INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
WHERE po.status = 'completed'
  AND (poi.quantity - poi.weight_deduction - poi.consumed_qty) > 0
  AND poi.category_id IS NOT NULL
GROUP BY po.branch_id, poi.category_id, poi.item_name;
```

### 4.3 Zero-Downtime Migration Strategy

```
Phase 1: สร้าง branch_stock table + populate seed data
         → ยังไม่มีผลกระทบ (code ยังไม่ใช้)
         
Phase 2: Deploy code ที่ UPSERT branch_stock พร้อม UPDATE categories.stock_kg
         → Dual-write mode (write ทั้ง 2 ที่)
         → categories.stock_kg ยังถูกใช้โดย report/query เก่า
         
Phase 3: Migrate queries จาก categories.stock_kg → branch_stock
         → เปลี่ยนทีละ endpoint (P0 → P1 → P2)
         
Phase 4: Remove categories.stock_kg (optionally)
         → เมื่อมั่นใจว่าไม่มี code ไหนใช้ categories.stock_kg แล้ว
```

---

### 4.4 Flow Redesign — รายละเอียด

#### 4.4.1 PO Create Flow

**Before:**
```php
// PurchaseOrder::createWithItems()
$this->db->query(
    "UPDATE categories SET stock_kg = stock_kg + ? WHERE id = ?",
    [$netQty, $categoryId]
);
```

**After:**
```php
// PurchaseOrder::createWithItems() — เพิ่ม UPSERT branch_stock
$stmt = $this->db->prepare(
    "INSERT INTO branch_stock (branch_id, category_id, item_name, stock_kg, unit_price)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         stock_kg   = stock_kg + VALUES(stock_kg),
         unit_price = ROUND(((stock_kg * unit_price) + (VALUES(stock_kg) * VALUES(unit_price))) / (stock_kg + VALUES(stock_kg)), 4),
         last_updated = NOW()"
);
$this->db->execute($stmt, [$branchId, $categoryId, $itemName, $netQty, $unitPrice]);

// ยังคง UPDATE categories.stock_kg ไปก่อน (dual-write)
```

#### 4.4.2 PO Cancel Flow

**Before:**
```php
$this->db->query(
    "UPDATE categories SET stock_kg = GREATEST(0, stock_kg - ?) WHERE id = ?",
    [$netQty, $item['category_id']]
);
```

**After:**
```php
$this->db->query(
    "UPDATE branch_stock
     SET stock_kg = GREATEST(0, stock_kg - ?)
     WHERE branch_id = ? AND category_id = ? AND item_name = ?",
    [$netQty, $branchId, $item['category_id'], $item['item_name']]
);
```

#### 4.4.3 SaleLot Confirm Flow (FIFO)

**Before:**
```php
// SaleLot::calculateFifoCost() — category_id เท่านั้น
"WHERE po.branch_id = ? AND poi.category_id = ? ..."
```

**After:**
```php
// SaleLot::calculateFifoCost() — เพิ่ม item_name match
public function calculateFifoCost($branch_id, $category_id, $item_name, $quantity_kg)
{
    $rows = $this->db->fetchAll(
        "SELECT poi.id, poi.quantity, poi.unit_price, poi.consumed_qty
         FROM purchase_order_items poi
         INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
         WHERE po.branch_id = ?
           AND poi.category_id = ?
           AND poi.item_name = ?        -- ← NEW: match item_name!
           AND po.status = 'completed'
           AND (poi.quantity - poi.consumed_qty) > 0
         ORDER BY po.created_at ASC
         FOR UPDATE",
        [$branch_id, $category_id, $item_name]
    );
    // ... same FIFO logic
}
```

และใน `deductStock()`:
```php
// แทน:
$this->db->query("UPDATE categories SET stock_kg = GREATEST(0, stock_kg - ?) WHERE id = ?", ...);

// ด้วย:
$this->db->query(
    "UPDATE branch_stock
     SET stock_kg = GREATEST(0, stock_kg - ?)
     WHERE branch_id = ? AND category_id = ? AND item_name = ?",
    [$remaining, $lot['branch_id'], $categoryId, $itemName]
);
```

#### 4.4.4 Stock Transfer Flow

**Before:**
```php
$poItemName = "โอนสต็อก (ST: {$st['reference_no']})";  // identity loss
```

**After:**
```php
// เก็บ original item_name จาก PO items ที่ถูกหัก
// และ UPSERT branch_stock สำหรับ destination
$stmt = $this->db->prepare(
    "INSERT INTO branch_stock (branch_id, category_id, item_name, stock_kg, unit_price)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         stock_kg   = stock_kg + VALUES(stock_kg),
         unit_price = ROUND(((stock_kg * unit_price) + (VALUES(stock_kg) * VALUES(unit_price))) / (stock_kg + VALUES(stock_kg)), 4),
         last_updated = NOW()"
);
$this->db->execute($stmt, [$toBranch, $categoryId, $originalItemName, $weightNeeded, $avgUnitPrice]);

// หัก branch_stock ต้นทาง
$this->db->query(
    "UPDATE branch_stock
     SET stock_kg = GREATEST(0, stock_kg - ?)
     WHERE branch_id = ? AND category_id = ? AND item_name = ?",
    [$weightNeeded, $fromBranch, $categoryId, $originalItemName]
);
```

#### 4.4.5 Reports/Dashboard Flow

**Before (ผิด):**
```sql
SELECT p.*, c.name, p.quantity, p.low_stock_threshold
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
```

**After (ถูก):**
```sql
SELECT
    bs.item_name,
    c.name AS category_name,
    bs.stock_kg AS quantity,
    c.alert_threshold AS low_stock_threshold,
    bs.unit_price AS cost,
    (bs.stock_kg * bs.unit_price) AS inventory_value
FROM branch_stock bs
LEFT JOIN categories c ON bs.category_id = c.id
WHERE bs.stock_kg > 0
  AND (?branch_id IS NULL OR bs.branch_id = ?branch_id)
ORDER BY c.name ASC, bs.item_name ASC
```

---

### 4.5 Backend Endpoint Changes

#### P0 Endpoints

| Endpoint | Method | ไฟล์ | การเปลี่ยนแปลง |
|:---------|:-------|:-----|:---------------|
| `POST /purchase-orders` | `createWithItems()` | `PurchaseOrder.php` | เพิ่ม UPSERT branch_stock |
| `POST /purchase-orders/{id}/cancel` | `cancel()` | `PurchaseOrder.php` | เปลี่ยนเป็น UPDATE branch_stock |
| `POST /sale-lots` | `store()` / `create()` | `SaleLot.php` | deductStock() → deduct branch_stock |
| `POST /sale-lots/{id}/confirm` | `updateStatus('confirmed')` | `SaleLot.php` | deductStock() → deduct branch_stock |
| `POST /sale-lots/{id}/cancel` | `updateStatus('cancelled')` | `SaleLot.php` | restoreStock() → restore branch_stock |
| `POST /stock-transfers/confirm` | `confirm()` | `StockTransfer.php` | decrement origin + UPSERT destination branch_stock |

**FIFO Logic Changes ใน `SaleLot.php`:**

| Method | Change |
|:-------|:-------|
| `calculateCost()` | รับ `$item_name` เพิ่ม → ส่งต่อให้ `calculateFifoCost()` |
| `calculateFifoCost()` | WHERE clause เพิ่ม `AND poi.item_name = ?` |
| `deductStock()` | เพิ่ม UPDATE branch_stock |
| `restoreStock()` | เพิ่ม UPDATE branch_stock |
| `recomputeCost()` | ส่ง item_name ให้ `calculateCost()` |

#### P1 Endpoints

| Endpoint | ไฟล์ | การเปลี่ยนแปลง |
|:---------|:-----|:---------------|
| `GET /inventory/categories` | `InventoryController.php` | ใช้ branch_stock แทน SUM PO items |
| `GET /inventory/category-items` | `InventoryController.php` | query branch_stock |
| `GET /inventory/stock-alerts` | `InventoryController.php` | เปลี่ยนเป็น branch-aware, query branch_stock |
| `GET /reports/dashboard-stats` | `ReportService.php` | total_sellers filter ตาม branch; low_stock_count query branch_stock |
| `GET /reports/inventory-report` | `ReportService.php` | เปลี่ยน FROM products → FROM branch_stock |

### 4.6 Frontend Changes

| ไฟล์ | การเปลี่ยนแปลง |
|:-----|:---------------|
| `inventory.js` | `refreshCategoryStock()` — ส่ง `branch_id` ไปกับ request (contract เดิม) |
| `inventory.js` | `renderStockAlerts()` — ส่ง `branch_id` ที่เลือกไปกับ request |
| `inventory.html` | stock alerts filter — เพิ่มตัวเลือกสาขา |
| `reports.html` | inventory report — ใช้ endpoint ใหม่ที่ query branch_stock |

---

## 5. Timeline & Dependencies

### Sprint A (P0) — ~5 วัน

| Day | งาน |
|:----|:----|
| Day 1 | สร้าง migration 051: branch_stock table + seed script |
| Day 2 | แก้ `PurchaseOrder::createWithItems()` + `cancel()` → upsert branch_stock |
| Day 3 | แก้ `SaleLot::calculateFifoCost()` + `deductStock()` → match item_name + update branch_stock |
| Day 4 | แก้ `SaleLot::restoreStock()` + `recomputeCost()` |
| Day 5 | แก้ `StockTransfer::confirm()` → preserve item_name + branch_stock upsert |

### Sprint B (P1) — ~2.5 วัน

| Day | งาน |
|:----|:----|
| Day 1 | แก้ `InventoryController::getCategories()` + `getCategoryItems()` → use branch_stock |
| Day 2 | แก้ `InventoryController::getStockAlerts()` → branch-aware |
| Day 3 | แก้ `ReportService::getInventoryReport()` + `getDashboardStats()` → branch_stock |

### Sprint C (P2) — ~2.5 วัน

| Day | งาน |
|:----|:----|
| Day 1 | UI: stock alert filter per-branch, inventory report UI |
| Day 2 | Frontend polish, loading states, error handling |
| Day 3 | End-to-end testing, regression testing |

### Dependencies
- Sprint A → Sprint B (B ต้องมี branch_stock data ก่อน)
- Sprint B → Sprint C (C ต้องมี endpoint ใหม่ก่อน)
- ทั้งหมดต้องรีวิว `SaleLot.php` FIFO logic ละเอียด (อาจมี edge case เรื่อง item_name = NULL)

---

## 6. Risk Assessment

### Risk 1: Data Integrity ระหว่าง Migration
- **ปัญหา:** branch_stock seed อาจไม่ตรงกับ consumed_qty จริง
- **Solution:** ใช้ transaction + verify query ทั้งก่อนและหลัง migration
- **Rollback:** `DROP TABLE IF EXISTS branch_stock;`

### Risk 2: Race Conditions
- **ปัญหา:** UPSERT branch_stock ใน concurrent request อาจเกิด race condition
- **Solution:** ใช้ `FOR UPDATE` ใน transaction, และ atomic UPDATE (`stock_kg = stock_kg + ?`)

### Risk 3: Item Name Mismatch
- **ปัญหา:** item_name ใน PO items อาจมี case ต่างกัน ("ทองแดง" vs "ทองแดง ")
- **Solution:** `TRIM(item_name)` ในทุก query + LOWER() ถ้าต้องการ case-insensitive matching

### Risk 4: Performance
- **ปัญหา:** branch_stock มี UNIQUE KEY 3 columns → UPSERT มี overhead เล็กน้อย
- **Solution:** OK สำหรับธุรกิจ SME (ปริมาณ transaction ไม่สูงมาก)

### Rollback Plan
```sql
-- Rollback migration 051
DROP TABLE IF EXISTS branch_stock;

-- Rollback code: revert to previous commit
git revert HEAD
```

---

## 7. Appendix: Source Files

| File | Path |
|:-----|:-----|
| InventoryController.php | `code/base-pos/api/Controllers/InventoryController.php` |
| SaleLotsController.php | `customizations/api/Controllers/SaleLotsController.php` |
| SaleLot.php | `customizations/api/Models/SaleLot.php` |
| StockTransfer.php | `customizations/api/Models/StockTransfer.php` |
| StockTransfersController.php | `customizations/api/Controllers/StockTransfersController.php` |
| PurchaseOrder.php | `customizations/api/Models/PurchaseOrder.php` |
| ReportService.php | `code/base-pos/api/Services/ReportService.php` |
| ReportsController.php | `code/base-pos/api/Controllers/ReportsController.php` |
| Router.php | `code/base-pos/api/Router.php` |
