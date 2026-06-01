# Secondhand POS — AI Agent Context

> ระบบจัดการร้านรับซื้อของเก่า (Junk Shop POS)
> Owner: Dr.solodev | Last Updated: 2026-06-01 (UI: tier buttons + stock card)

---

## Business Model

**ร้านรับซื้อของเก่า ≠ ร้านค้าปลีกทั่วไป**
- **รับซื้อ (Purchase):** จากผู้ขายรายย่อยหลายคน — แต่ละคนเอาของมาขายทีละน้อย ต้องกรอกข้อมูลผู้ขาย + ชั่งน้ำหนัก + ให้ราคา
- **ขาย Lot (Sale Lots):** ขายเทกองให้ศูนย์ใหญ่ — รวมของหลายประเภทใน Lot เดียว, คำนวณกำไรแบบ FIFO
- **POS retail: ยังมีให้ใช้แต่น้อยกว่าธุรกิจหลัก**

| Direction | Counterparty | Volume | System |
|-----------|-------------|--------|--------|
| ← รับซื้อ (in) | ผู้ขายรายย่อย | ทุกวัน, หลายคน | Purchase Orders |
| → ขาย Lot (out) | ศูนย์ใหญ่/โรงงาน | นานๆ ที, ปริมาณมาก | Sale Lots |
| → ขายปลีก | ลูกค้าทั่วไป | ปกติ | POS / Sales |

---

## Architecture

### Two-layer System

```
code/
├── base-pos/              # Core POS (goragodwiriya/pos-system) — patched
│   ├── api/               # PHP Backend — ทั้ง core (built-in controllers) + custom controllers registered
│   │   ├── Router.php     # **All routes registered here** (core + custom)
│   │   ├── Controller.php # Base controller class (custom controllers extend this)
│   │   ├── config.php     # DB config, JWT secret
│   │   ├── autoload.php   # PSR-4 autoloader (loads core + custom controllers/models)
│   │   ├── Core/          # Database, Auth, Response, Model, Logger
│   │   ├── Models/        # Built-in: Inventory, Product, Sale, SaleItem, Category, User, Customer, ActivityLog, Setting
│   │   └── Controllers/   # Built-in: Auth, Inventory, Sales, Users, Settings, Customers, Reports
│   ├── admin/             # **Admin pages** — updated in-place
│   ├── pos/               # POS terminal
│   └── assets/            # CSS, JS (common.js, config.js)
│
└── customizations/        # Custom code (mounted INTO base-pos via autoloader)
    ├── api/Models/        # Custom: Branch, Seller, ItemCondition, PurchaseOrder, PurchaseItemCatalog, SaleLot
    ├── api/Controllers/   # Custom: Branches, Sellers, PurchaseOrders, ItemConditions, PriceTiers, PurchaseItemCatalog, SaleLots
    ├── database/migrations/  # 021 migrations (numbered sequentially)
    └── frontend-react/    # DEPRECATED — React v2 app, no longer active delivery target
```

### Key Design Decisions

1. **Custom controllers/models** extend `Controller`/`Model` from `base-pos/api/Core/`
2. **Autoloader** (`base-pos/api/autoload.php`) loads from both:
   - `base-pos/api/Controllers/` and `base-pos/api/Models/`
   - `customizations/api/Controllers/` and `customizations/api/Models/`
3. **Router** (`base-pos/api/Router.php`) — ALL routes registered here (both core + custom)
4. **Config** (`base-pos/assets/js/config.js`) — `window.apiPath = '/api/index.php'`
5. **API Helper** (`base-pos/assets/js/common.js`) — `apiRequest(endpoint, method, data)` calls `${apiPath}/${endpoint}`
6. **No framework** — Vanilla PHP + Vanilla JS (no jQuery, no React for base-pos)

---

## Database Schema (Custom Tables)

### Purchase Flow Tables

| Migration | Table | Purpose |
|-----------|-------|---------|
| 001 | `branches` | 4 สาขา, มี code + address |
| 002 | `sellers` | ผู้ขาย — name, id_card, phone, address, vehicle_plate, branch_id |
| 003 | `item_conditions` | deprecated (ถูก replace โดย weight_deduction) |
| 004 | `purchase_orders` | ใบรับซื้อ — reference_no (PO-YYYYMMDD-NNN), seller, branch, items, total |
| 004 | `purchase_order_items` | รายการใน PO — catalog, category, qty, weight_deduction, unit_price, price_tier |
| 007 | `price_tiers` | ราคา 3 ระดับต่อ 1 category (tier1/tier2/tier3) |
| 008 | — | ADD price_tier column to purchase_order_items |
| 010 | — | ADD consumed_qty / fifo_cost to purchase_order_items |
| 012 | `purchase_item_catalog` | Master catalog — code, name, category_id, default_price, default_unit |
| 013 | — | REPLACE condition_id with weight_deduction in purchase_order_items |
| 015 | `purchase_item_catalog` | ADD price_tier1/2/3 columns (fixed 3 tiers) |
| 016 | `purchase_item_catalog` | ADD tier_prices JSON column (dynamic tiers, replaces price_tier1/2/3) |
| 017 | — | ADD expenses JSON to sale_lots |
| 018 | — | Rename tier labels to "บิล1/2/3" (was duplicate 017) |
| 019 | — | (No-op / placeholder) |
| 020 | — | FIFO indexes + atomic conditional UPDATE for consumed_qty |
| 021 | `branches` | ADD cost_method ENUM('fifo','weighted') default 'fifo' |

### Sale Lot Tables

| Migration | Table | Purpose |
|-----------|-------|---------|
| 009 | `sale_lots` | ขาย Lot — reference_no (SO-YYYYMMDD-NNN), buyer, branch, total_amount, total_cost, profit (GENERATED), status (draft/confirmed/cancelled) |
| 009 | `sale_lot_items` | รายการใน Lot — category_id, qty_kg, unit_price, subtotal (GENERATED), fifo_cost |
| 010 | — | consumed_qty tracking for FIFO costing |

### Seed Data

| Migration | Content |
|-----------|---------|
| 005 | Default categories (พลาสติก, กระดาษ, เหล็ก, อลูมิเนียม, ทองแดง, ทองเหลือง, สแตนเลส, แก้ว, ยาง, อิเล็กทรอนิกส์, แบตเตอรี่) |
| 006 | Demo data |

### Key Column Notes

- `products.category_id` → FK to `categories(id)` with `ON DELETE SET NULL` (cannot convert to free-text)
- `sale_lots.profit` = `GENERATED ALWAYS AS (total_amount - total_cost) STORED`
- `sale_lot_items.subtotal` = `GENERATED ALWAYS AS (quantity_kg * unit_price) STORED`
- `sale_lots.reference_no` format: `SO-{BRANCH_CODE}-YYYYMMDD-NNN` (per branch, per day sequential — branch_code added by BUG-07 fix)
- `purchase_orders.reference_no` format: `PO-B{BRANCH_ID}-YYYYMMDD-NNN` (per branch, per day sequential)

---

## API Endpoints

### Core System (built-in)
```
GET    /auth/login, /auth/verify           # Authentication
GET    /inventory/categories, /products     # Inventory management
POST   /inventory/categories, /products     # CRUD
GET    /inventory/category, /product        # Single resource
PUT    /inventory/category, /product        # Update
DELETE /inventory/category, /product        # Delete
GET    /inventory/low-stock, /transactions  # Stock monitoring
POST   /sales/create                        # Retail sales
GET    /sales/list, /details, /export       # Sales history
POST   /sales/void                          # Void sale
GET    /reports/dashboard-stats, /sales-chart, /recent-sales, ...
GET    /reports/purchase-report             # Purchase report (with filters + CSV)
GET    /reports/sale-lot-report             # Sale lot report (with filters + CSV)
GET    /reports/sale-lot-chart              # Sale lot chart data
GET    /reports/recent-sale-lots            # Recent sale lots for dashboard
GET    /users/all, /users/user              # User management
POST   /users, /users/change-password       # CRUD + profile
GET    /customers, /customers/customer      # Customer management
GET    /settings/store, /settings/system    # Settings
GET    /settings/backup/*                   # Backup management
```

### Custom — Purchase Orders
```
GET    /purchase-orders                     # List with filters (?branch_id=&status=&limit=)
POST   /purchase-orders                     # Create (branch_id, seller_id, items[], payment_method, notes)
GET    /purchase-orders/order?id=           # Get single PO with items
POST   /purchase-orders/cancel?id=          # Cancel PO (restore stock)
```

### Custom — Purchase Catalog
```
GET    /purchase-catalog                    # List all catalog items
POST   /purchase-catalog                    # Create item
GET    /purchase-catalog/search?q=          # Search by code or name (auto-fill on exact match)
GET    /purchase-catalog/item?id=           # Get single item
PUT    /purchase-catalog/item?id=           # Update item
```

### Custom — Sale Lots
```
GET    /sale-lots                           # List with filters (?branch_id=&status=&date_from=&date_to=)
POST   /sale-lots                           # Create (branch_id, buyer_name, sale_date, items[], notes)
GET    /sale-lots/sale-lot?id=              # Get single lot with items + profit_breakdown
PUT    /sale-lots/sale-lot?id=              # Update (draft only)
DELETE /sale-lots/sale-lot?id=              # Delete (draft only)
POST   /sale-lots/confirm?id=               # Confirm → deduct FIFO stock
POST   /sale-lots/cancel?id=                # Cancel → restore stock
```

### Custom — Support
```
GET    /branches, /branches/active          # Branch list
GET    /branches/summary                    # Branch summary
GET    /sellers, /sellers/search?q=         # Seller list / search
POST   /sellers                             # Create seller
PUT    /sellers/seller?id=                  # Update seller
POST   /sellers/blacklist, /unblacklist     # Blacklist management
GET    /item-conditions                     # (deprecated)
GET    /price-tiers                         # Get categories with tier prices
PUT    /price-tiers/category?id=            # Update tier prices per category
```

---

## UI Structure (PHP Base POS — Active)

### Admin Pages — `code/base-pos/admin/`
| Page | JS | Purpose |
|------|-----|---------|
| `index.html` | `dashboard.js` | Dashboard with stats |
| `purchase-orders.html` | `purchase-orders.js` | **รับซื้อของ** — catalog autocomplete, seller search, item table, PO creation |
| `sale-lots.html` | `sale-lots.js` | **ขาย Lot** — create/edit/confirm/delete lots, line items, profit tracking |
| `sellers.html` | `sellers.js` | Seller management (CRUD, blacklist) |
| `inventory.html` | `inventory.js` | Products + Categories + Price Tiers |
| `price-tiers.html` | `price-tiers.js` | Price tier management per category |
| `sales.html` | `sales.js` | Retail sales history |
| `reports.html` | `reports.js` | Various reports |
| `settings.html` | `settings.js` | System settings |
| `users.html` | `users.js` | User management |

### POS Terminal — `code/base-pos/pos/index.html` → `pos.js`

---

## Business Logic: Key Flows

### Purchase Order (รับซื้อ)
```
1. Select branch → search/select seller (or create new)
2. Search catalog item by code/name → auto-fill category + unit
3. Enter: weight (kg), weight deduction (kg), unit price
4. **Before adding items**: select price tier first (buttons show actual labels/prices from catalog item)
5. Click "เพิ่มรายการ" → item added to cart table, tier level stays selected
6. Next item auto-gets same tier price — no need to re-select
6. Repeat steps 2-5 for multiple items
7. Set payment method + notes
8. Click "บันทึกใบรับซื้อ"
9. System: generates PO-YYYYMMDD-NNN, records items, updates inventory
10. Receipt modal shown
```

### Sale Lot (ขาย Lot)
```
1. Open modal: enter buyer name, sale date, select branch
2. Add line items: select category, enter kg, enter price/kg
3. Click "+ เพิ่มรายการ" for more items
4. System: calculates subtotal, shows running total
5. Click "บันทึก Lot ขาย" → confirm dialog
6. Lot saved as 'draft'
9. From list: ยืนยัน → deducts FIFO stock from PO items
10. From list: แก้ไข → only available for draft
11. From list: ลบ → only available for draft
```

### Catalog Auto-fill
```
1. User types in catalog search input
2. System searches purchase_item_catalog by code OR name
3. If exact match (code or name = input): auto-fills category, unit, default price
4. If multiple results: dropdown shown, user clicks to select
5. Items MUST be selected from catalog — no free-text items allowed
```

### FIFO Costing (sale_lots → purchase_order_items)
```
1. On lot confirm: calculate cost for each lot item by consuming PO items
2. Consume oldest PO items first (FIFO by created_at)
3. Update consumed_qty on purchase_order_items (atomic conditional UPDATE for race safety)
4. On lot cancel: restore consumed_qty using LIFO (atomic conditional UPDATE)
5. profit = total_amount - total_cost (GENERATED column)
6. TOCTOU protection: SELECT ... FOR UPDATE locks the lot row at transaction start
```

---

## Development State

### ✅ Completed
- All 21 database migrations created and documented
- All custom Models + Controllers built
- Code review hardening (2026-05-28): 5 blockers + 4 medium + 1 bonus bug fixed — PO/SL scope checks, JWT branch_id, FIFO regression, ID card validation, CORS env, backup perms, input validation, tier sanitize
- Inventory SKU/cost field fixes: removed cost_price field (ทุนมาจาก PO), SKU manual entry (ตัวเลข 1, 2, 3...), null category_id handling
- Inventory stock card: real-time category stock_kg display with visual bar graph
- Inventory table: changed from `products` (retail) to `purchase_item_catalog` — shows catalog items with tier prices, CRUD via modal, search/filter by category/status
- Sellers duplicate entry: error propagation from model to controller for duplicate id_card/phone
- Router has ALL routes registered
- Purchase Orders page (PHP): catalog autocomplete, seller search, item row + cart table, dynamic tier buttons (select tier first → price auto-fill on all items)
- Sale Lots page (PHP): full CRUD with modal, line items, confirm/delete, profit display
- Sidebar: "ขาย Lot" link added to all admin pages
- Price Tiers: dynamic tier pricing (add/remove levels per catalog item, stored as JSON)
- Purchase Item Catalog: master catalog management
- Reports: 4 new endpoints (purchase-report, sale-lot-report, sale-lot-chart, recent-sale-lots)
- Dashboard: 3 new stat cards + sale lot chart + recent sale lots table
- Responsive CSS: breakpoints at 768px and 576px
- Weighted average cost: per-branch cost_method (fifo/weighted), migration 021
- FIFO race fix: atomic conditional UPDATE for consumed_qty, migration 020
- TOCTOU fix: FOR UPDATE locking in SaleLot confirm/cancel flow
- Security: JWT_SECRET moved to .env, all Docker credentials to .env (docker-compose.yml)
- Console cleanup: 9 console.log() calls removed from 3 JS files
- Test suite: expanded from 33→58 tests (47→58 this session) — PO cancel, FIFO confirm/cancel/cost, duplicate phone seller, PO invalid branch, blacklist/unblacklist seller, overstock confirm rejection, update/delete draft lot
- Bug fixes: PO cancel undefined method (PurchaseOrder::updateStatus), SL reference_no collision (branch code in prefix), deductStock/restoreStock execute→query, index.php catch(Exception) → catch(\Throwable)
- Build: all 58 tests pass

### ⏳ Pending / Future
- Auto-generate purchase from catalog low-stock alerts
- Tax/nightly batch reports
- PHPUnit test framework integration (currently bash/curl)
- HTTPS setup
- Rate limiting on auth endpoint

### 🔴 Known Issues
- `item-conditions` is deprecated in favor of `weight_deduction` but old UI still references it
- No validation for duplicate seller ID card
- FIFO cost calculation recalculates on every confirm (no cost locking — mitigated by FOR UPDATE)

---

## Conventions

### Code Style
- **PHP:** Psr-4-ish (namespaces not strict, files auto-loaded by path)
- **JS:** Vanilla ES6, no jQuery, no framework
- **CSS:** Custom properties in `:root`, BEM-ish naming, utility classes prefixed `.text-`, `.badge-`
- **Database:** Migrations numbered sequentially (`NNN_description.sql`)

### Naming
- Routes: `kebab-case/action` (e.g. `purchase-orders/order`)
- Tables: `snake_case` plural (e.g. `purchase_order_items`)
- JS IDs: `camelCase` (e.g. `savePOBtn`, `itemCatalogResults`)
- CSS Classes: `kebab-case` (e.g. `data-table`, `cart-summary`)
- PHP: `PascalCase` for classes (e.g. `SaleLotsController`), `camelCase` for methods

### API Patterns
- Method: `apiRequest(endpoint, method, data)` → `fetch('/api/index.php/' + endpoint, ...)`
- Response: `{ status: 'success'|'error', data: ..., message: '...' }`
- Auth: Bearer JWT token in Authorization header
- Single resource: `?id=` query param pattern (e.g. `sale-lots/sale-lot?id=123`)

### Route Registration Pattern
```php
$this->routes[] = ['route' => 'resource/action', 'controller' => 'FooController', 'method' => 'bar', 'verb' => 'GET'];
```
- Verb defaults to any if not set
- `$id` from query param passed to controller method
- Routes matched by exact string + verb

### Migration Strategy
- **DO NOT** modify `base-pos/database/pos_system.sql`
- All changes in `customizations/database/migrations/`
- Run via `run-migrations.sh`
- Run migrations in numerical order

---

## File Map Summary

### Custom Controllers — `code/customizations/api/Controllers/`
| File | Routes |
|------|--------|
| `BranchesController.php` | branches CRUD + active + summary |
| `SellersController.php` | sellers CRUD + search + blacklist |
| `PurchaseOrdersController.php` | purchase-orders CRUD + cancel |
| `PurchaseItemCatalogController.php` | catalog CRUD + search |
| `SaleLotsController.php` | sale-lots CRUD + confirm + cancel |
| `PriceTiersController.php` | price-tiers CRUD per category |
| `ItemConditionsController.php` | (deprecated) conditions list |

### Custom Models — `code/customizations/api/Models/`
| File | Table |
|------|-------|
| `Branch.php` | branches |
| `Seller.php` | sellers |
| `PurchaseOrder.php` | purchase_orders + items + FIFO cost |
| `PurchaseItemCatalog.php` | purchase_item_catalog |
| `SaleLot.php` | sale_lots + items + FIFO cost |
| `ItemCondition.php` | item_conditions |

### Key Helper Files
| File | Role |
|------|------|
| `base-pos/assets/js/config.js` | `window.apiPath = '/api/index.php'` |
| `base-pos/assets/js/common.js` | `apiRequest()`, `formatCurrency()`, `showNotification()`, login/logout |
| `base-pos/assets/css/styles.css` | All CSS custom properties + component styles |
| `base-pos/api/autoload.php` | PSR-4 autoloader for both base-pos and customizations |
| `base-pos/api/Router.php` | Route registration + dispatch + auth |
| `customizations/api/Services/ReportService.php` | Report engine — purchase-report, sale-lot-report, sale-lot-chart |

---

## Quick Reference

### Server URL
- Admin: `http://localhost:8080/admin/`
- API: `http://localhost:8080/api/index.php/`
- Default login: admin / admin

### Common JS Patterns
```javascript
// API call (from common.js)
const res = await apiRequest('sale-lots', 'POST', payload);
// res = { status: 'success', data: {...}, message: '...' }

// Notification
showNotification('บันทึกสำเร็จ', 'success');
showNotification('ผิดพลาด', 'error');

// Format currency
formatCurrency(1234.50);  // "฿1,234.50"
```
