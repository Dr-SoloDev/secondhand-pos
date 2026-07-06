# Secondhand POS — AI Agent Context

> ระบบจัดการร้านรับซื้อของเก่า (Junk Shop POS)
> Owner: Dr.solodev | Last Updated: 2026-07-03

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
├── base-pos/              # Core POS (goragodwiriya/pos-system) — patched in-place
│   ├── api/               # PHP Backend — core + custom controllers registered
│   │   ├── Router.php     # **All routes registered here** (core + custom)
│   │   ├── Controller.php # Base controller class (custom controllers extend this)
│   │   ├── config.php     # DB config, JWT secret
│   │   ├── autoload.php   # PSR-4 autoloader (loads core + custom controllers/models)
│   │   ├── Core/          # Database, Auth, Response, Model, Logger
│   │   ├── Services/      # ReportService, TokenService, BackupService, ValidationService
│   │   ├── Models/        # Built-in: Inventory, Product, Sale, SaleItem, Category, User, Customer, ActivityLog, Setting
│   │   └── Controllers/   # Built-in: Auth, Inventory, Sales, Users, Settings, Customers, Reports
│   ├── admin/             # Admin HTML pages
│   ├── pos/               # POS terminal
│   └── assets/            # CSS, JS (all admin JS in assets/js/)
│
├── customizations/        # Custom code (mounted INTO base-pos via autoloader)
│   ├── api/Models/        # 8 Models: Branch, Seller, ItemCondition, PurchaseOrder, PurchaseItemCatalog, SaleLot, StockTransfer, BusinessExpense
│   ├── api/Controllers/   # 10 Controllers: Branches, Sellers, PurchaseOrders, ItemConditions, PriceTiers, PurchaseItemCatalog, SaleLots, StockTransfers, PhotoUpload, Financial
│   ├── database/          # 38 migrations + run-migrations.sh
│
├── tests/api/             # 9 test functions (54 assertions) — bash/curl
├── uploads/purchase-orders/  # Photo uploads (WF-01)
└── database/              # security-migrations.sql
```

### Key Design Decisions

1. **Custom controllers/models** extend `Controller`/`Model` from `base-pos/api/Core/`
2. **Autoloader** (`base-pos/api/autoload.php`) loads from both:
   - `base-pos/api/Controllers/` and `base-pos/api/Models/`
   - `customizations/api/Controllers/` and `customizations/api/Models/`
3. **Router** (`base-pos/api/Router.php`) — ALL routes registered here (both core + custom)
4. **JS Admin files** stored in `base-pos/assets/js/` (not `admin/`)
5. **Config** (`base-pos/assets/js/config.js`) — `window.apiPath = '/api/index.php'`
6. **API Helper** (`base-pos/assets/js/common.js`) — `apiRequest(endpoint, method, data)` calls `${apiPath}/${endpoint}`
7. **No framework** — Vanilla PHP + Vanilla JS (no jQuery, no React for base-pos)

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
| 020 | — | FIFO indexes + atomic conditional UPDATE for consumed_qty |
| 021 | `branches` | ADD cost_method ENUM('fifo','weighted') default 'fifo' |
| 036 | `purchase_order_photos` | ADD purchase_order_id FK + make item_id nullable |

### Sale Lot Tables

| Migration | Table | Purpose |
|-----------|-------|---------|
| 009 | `sale_lots` | ขาย Lot — reference_no (SO-YYYYMMDD-NNN), buyer, branch, total_amount, total_cost, profit (GENERATED), status (draft/confirmed/cancelled) |
| 009 | `sale_lot_items` | รายการใน Lot — category_id, qty_kg, unit_price, subtotal (GENERATED), fifo_cost |
| 029 | `sale_lots` | ADD actual_revenue, actual_revenue_note, actual_revenue_date |
| 034 | `sale_lot_items` | ADD catalog_id + item_name, category_id nullable |
| 035 | `sale_lots` | ADD updated_by FK |

### Stock Transfer Tables

| Migration | Table | Purpose |
|-----------|-------|---------|
| 024 | `stock_transfers` | โอนสต็อกระหว่างสาขา — from/to branch, category, weight_kg, status (pending/confirmed/cancelled) |
| 025 | `stock_transfers` | ADD logistics fields: transporter_name, vehicle_plate, received_weight_kg, receive_note |

### Business Expenses

| Migration | Table | Purpose |
|-----------|-------|---------|
| 030 | `business_expenses` | ค่าใช้จ่ายธุรกิจ — branch, date, category, amount, note |

### Categories & Catalog

| Migration | Table | Purpose |
|-----------|-------|---------|
| 022 | `categories` | ADD branch_id (later reverted by 027) |
| 026 | `purchase_item_catalog` | Populate category_id from purchase history |
| 027 | `categories` | Merge duplicate per-branch categories → global unique names |
| 028 | `categories` | ADD default_unit (e.g. 'กก.', 'ลัง') |
| 032 | `categories` | ADD requires_precious_receipt flag |
| 033 | `categories` | ADD alert_threshold for low-stock alerts |

### Sellers

| Migration | Table | Purpose |
|-----------|-------|---------|
| 031 | `sellers` | ADD blacklist_reason, blacklisted_at |

### Security / Auth

| Migration | Table | Purpose |
|-----------|-------|---------|
| 037 | `login_attempts` | IP-based rate limiting (persistent, replaces file-based) |
| 038 | `token_blocklist` | JWT revocation (jti + expiry index) |

### Seed Data

| Migration | Content |
|-----------|---------|
| 005 | Default categories (พลาสติก, กระดาษ, เหล็ก, etc.) |
| 006 | Demo data (duplicate number — two files: `006_change_to_three_tier_pricing.sql` + `006_seed_demo_data.sql`) |

### Key Column Notes

- `sale_lots.profit` = `GENERATED ALWAYS AS (total_amount - total_cost) STORED`
- `sale_lot_items.subtotal` = `GENERATED ALWAYS AS (quantity_kg * unit_price) STORED`
- `sale_lots.reference_no` format: `SO-{BRANCH_CODE}-YYYYMMDD-NNN`
- `purchase_orders.reference_no` format: `PO-B{BRANCH_ID}-YYYYMMDD-NNN`
- `stock_transfers.reference_no` auto-generated `TF-{BRANCH_CODE}-YYYYMMDD-NNN`

---

## API Endpoints

### Core System (built-in)
```
GET    /auth/login, /auth/verify                  # Authentication
POST   /auth/logout                               # Logout (revoke token)
GET    /inventory/categories, /products           # Inventory management
POST   /inventory/categories, /products           # CRUD
POST   /inventory/set-threshold                   # Set stock alert threshold
GET    /inventory/stock-alerts                    # Get stock alerts
GET    /inventory/category, /product              # Single resource
PUT    /inventory/category, /product              # Update
DELETE /inventory/category, /product              # Delete
GET    /inventory/low-stock, /transactions        # Stock monitoring
POST   /sales/create                              # Retail sales
GET    /sales/list, /details, /export             # Sales history
POST   /sales/void                                # Void sale
GET    /reports/dashboard-stats                   # Dashboard
GET    /reports/sales-chart, /purchase-chart      # Charts
GET    /reports/recent-sales, /recent-purchases, /recent-sale-lots
GET    /reports/sales-report, /product-sales, /inventory-report
GET    /reports/cashier-performance, /tax-report
GET    /reports/purchase-report, /sale-lot-report, /sale-lot-chart
GET    /users/all, /users/user                    # User management
POST   /users, /users/change-password             # CRUD + profile
GET    /users/profile, /users/activity-log        # Profile + audit
PUT    /users/profile, /users/change-own-password # Self-service
GET    /customers, /customers/customer            # Customer management
POST   /customers                                 # Create customer
GET    /settings/store, /settings/system          # Settings
POST   /settings/store, /settings/system          # Update
POST   /settings/backup/create, /restore          # Backup
GET    /settings/backup/history, /download        # Backup management
POST   /settings/backup/delete                    # Delete backup
```

### Custom — Purchase Orders
```
GET    /purchase-orders                           # List with filters (?branch_id=&status=&limit=)
POST   /purchase-orders                           # Create (branch_id, seller_id, items[], payment_method, notes)
GET    /purchase-orders/order?id=                 # Get single PO with items
POST   /purchase-orders/cancel?id=                # Cancel PO (restore stock)
POST   /purchase-orders/photos                    # Upload photo (HMAC or JWT)
GET    /purchase-orders/photos                    # List photos for PO
GET    /purchase-orders/photo-token               # Generate HMAC token for QR handoff
```

### Custom — Purchase Catalog
```
GET    /purchase-catalog                          # List all catalog items
POST   /purchase-catalog                          # Create item
GET    /purchase-catalog/search?q=                # Search by code or name
GET    /purchase-catalog/item?id=                 # Get single item
PUT    /purchase-catalog/item?id=                 # Update item
DELETE /purchase-catalog/item?id=                 # Delete item
POST   /purchase-catalog/update-category          # Bulk update category
GET    /purchase-catalog/price-board              # Price board data (all branches)
```

### Custom — Sale Lots
```
GET    /sale-lots                                 # List with filters
POST   /sale-lots                                 # Create (auto-confirm or draft)
GET    /sale-lots/sale-lot?id=                    # Get single lot with items + profit_breakdown
PUT    /sale-lots/sale-lot?id=                    # Update (draft only)
DELETE /sale-lots/sale-lot?id=                    # Delete (draft only)
POST   /sale-lots/confirm?id=                     # Confirm → deduct FIFO stock
POST   /sale-lots/cancel?id=                      # Cancel → restore stock
POST   /sale-lots/record-revenue?id=              # Record actual revenue (actual_revenue)
```

### Custom — Stock Transfers
```
GET    /stock-transfers                           # List with filters (?status=)
POST   /stock-transfers                           # Create transfer (from_branch, to_branch, category, weight)
POST   /stock-transfers/confirm                   # Confirm transfer (deduct from, add to)
POST   /stock-transfers/cancel                    # Cancel transfer (pending only)
```

### Custom — Financial Summary (admin only)
```
GET    /financial/summary                         # Dashboard cards: purchase total, revenue, expenses, kg, lot count
GET    /financial/lot-revenues                    # Lot revenue breakdown
GET    /financial/purchase-by-category            # Purchase spending by category
GET    /financial/expenses                        # List business expenses
POST   /financial/expenses                        # Create business expense
DELETE /financial/expenses                        # Delete business expense
GET    /financial/export                          # Export CSV
```

### Custom — Support
```
GET    /branches, /branches/active                # Branch list
GET    /branches/summary                          # Branch summary
POST   /branches                                  # Create branch
GET    /branches/branch, PUT /branches/branch     # Single branch CRUD
GET    /sellers, /sellers/search?q=               # Seller list / search
POST   /sellers                                   # Create seller
GET    /sellers/seller                            # Get seller
PUT    /sellers/seller                            # Update seller
POST   /sellers/blacklist, /unblacklist           # Blacklist management
GET    /sellers/history                           # Seller purchase history
POST   /sellers/photo                             # Upload seller photo
GET    /item-conditions                           # (deprecated)
GET    /price-tiers                               # Get categories with tier prices
POST   /price-tiers                               # Create catalog item (via price-tiers)
PUT    /price-tiers/category?id=                  # Update tier prices per category
```

---

## UI Structure (PHP Base POS — Active)

### Admin Pages — `code/base-pos/admin/` (JS in `code/base-pos/assets/js/`)
| Page | JS | Purpose |
|------|-----|---------|
| `index.html` | `dashboard.js` | Dashboard with stats + charts |
| `purchase-orders.html` | `purchase-orders.js` | **รับซื้อของ** — catalog autocomplete, seller search, item table, PO creation |
| `sale-lots.html` | `sale-lots.js` | **ขาย Lot** — create/edit/confirm/delete lots, line items, profit tracking |
| `sellers.html` | `sellers.js` | Seller management (CRUD, blacklist, photo) |
| `inventory.html` | `inventory.js` | Products + Categories + Price Tiers + Stock Alerts |
| `price-tiers.html` | `price-tiers.js` | Price tier management per catalog item |
| `price-board.html` | — | Price board display (embedded via price-tiers.js?) |
| `branches.html` | `branches.js` | Branch management (CRUD + cost_method) |
| `stock-transfers.html` | — | Stock transfers between branches |
| `expenses.html` | `expenses.js` | Business expenses management |
| `financial-summary.html` | `financial-summary.js` | Financial dashboard (admin) |
| `sales.html` | `sales.js` | Retail sales history |
| `reports.html` | `reports.js` | Various reports (purchase, sale lot, charts, CSV export) |
| `settings.html` | `settings.js` | System settings + backup management |
| `users.html` | `users.js` | User management + activity log |

### Other JS
| JS | Purpose |
|------|---------|
| `common.js` | `apiRequest()`, `formatCurrency()`, `showNotification()`, login/logout |
| `config.js` | `window.apiPath = '/api/index.php'` |
| `login.js` | Login page |
| `animations.js` | UI micro-interactions |
| `photo-upload.js` | Photo upload widget (WF-01) |
| `pos.js` | POS terminal (`code/base-pos/pos/`) |

---

## Business Logic: Key Flows

### Purchase Order (รับซื้อ)
```
1. Select branch → search/select seller (or create new)
2. Search catalog item by code/name → auto-fill category + unit
3. Enter: weight (kg), weight deduction (kg), unit price
4. Select price tier first → price auto-fills on all items
5. Add multiple items to cart table
6. Set payment method + notes
7. Save → generates PO reference, records items, updates inventory
8. Receipt modal shown
```

### Sale Lot (ขาย Lot)
```
1. Create modal: buyer name, date, branch, line items
2. Each item: select category (or catalog item), enter kg, enter price/kg
3. Save as 'draft' or auto-confirm
4. From list: ยืนยัน → deducts FIFO stock from PO items
5. From list: แก้ไข → draft only
6. From list: ลบ → draft/cancelled only
7. Record actual revenue after payment received
```

### Stock Transfer (โอนสต็อก)
```
1. Select from_branch → to_branch (must differ)
2. Select category, enter weight_kg
3. Optional: transporter_name, vehicle_plate
4. Save as 'pending'
5. Confirm → deducts from from_branch, adds to to_branch
6. Cancel → pending only
```

### Photo Upload (WF-01)
```
1. PO created → QR code printed on receipt
2. QR encodes HMAC token + PO ID
3. Staff scans QR with phone → uploads photos
4. Photos stored in uploads/purchase-orders/{po_id}/
5. Also uploadable via staff JWT auth from admin UI
```

### Catalog Auto-fill
```
1. User types in catalog search input
2. System searches purchase_item_catalog by code OR name
3. If exact match: auto-fills category, unit, default price
4. If multiple results: dropdown shown
5. Items MUST be selected from catalog — no free-text items allowed
```

### FIFO Costing (sale_lots → purchase_order_items)
```
1. On lot confirm: consume oldest PO items first (FIFO by created_at)
2. Atomic conditional UPDATE for consumed_qty (race safety)
3. On lot cancel: restore consumed_qty using LIFO
4. profit = total_amount - total_cost (GENERATED column)
5. TOCTOU protection: SELECT ... FOR UPDATE locks lot row
```

---

## Development State

### ✅ Completed
- All 38 database migrations
- 8 custom Models + 10 custom Controllers + 4 base Services
- 9 test functions (54 assertions) — all passing
- FIFO costing with atomic consumed_qty + TOCTOU FOR UPDATE locking
- Weighted average cost (per-branch cost_method)
- Stock Transfers between branches (pending → confirm/cancel)
- Photo upload (WF-01): HMAC token + QR-based mobile upload
- Business Expenses tracking
- Financial Summary dashboard (revenue, expenses, purchase totals, kg)
- Actual revenue tracking on sale lots
- Price board endpoint for all branches
- Stock alert thresholds per category
- Blacklist reason/timestamp tracking
- Precious metals receipt flag
- Token blocklist for JWT revocation
- Login attempt rate limiting (IP-based)
- Reports: purchase-report, sale-lot-report, sale-lot-chart, + more
- Dashboard charts + recent sale lots
- Responsive CSS (768px, 576px breakpoints)
- JWT in .env, Docker secrets externalized, CORS from env
- Console cleanup: 9 console.log() removed
- Bug fixes: PO cancel, SL reference_no collision, PDO execute→query
- ReportsController expanded with multiple new endpoint

### ⏳ Pending / Future
- Auto-generate PO from catalog low-stock alerts
- PHPUnit test framework integration
- HTTPS setup
- Rate limiting on auth (partially done via login_attempts table)
- Tax/nightly batch reports

### 🔴 Known Issues
- `item-conditions` is deprecated in favor of `weight_deduction` but old UI still references it
- FIFO cost calculation recalculates on every confirm (no cost locking — mitigated by FOR UPDATE)
- No dedicated test file for StockTransfers, Financial, PhotoUpload, BusinessExpenses

---

## Conventions

### Code Style
- **PHP:** Psr-4-ish (namespaces not strict, files auto-loaded by path)
- **JS:** Vanilla ES6, no jQuery, no framework
- **CSS:** Custom properties in `:root`, BEM-ish naming, utility classes prefixed `.text-`, `.badge-`
- **Database:** Migrations numbered sequentially (NNN_description.sql)

### Naming
- Routes: `kebab-case/action` (e.g. `purchase-orders/order`)
- Tables: `snake_case` plural (e.g. `purchase_order_items`)
- JS IDs: `camelCase` (e.g. `savePOBtn`, `itemCatalogResults`)
- CSS Classes: `kebab-case` (e.g. `data-table`, `cart-summary`)
- PHP: `PascalCase` for classes (e.g. `SaleLotsController`), `camelCase` for methods

### API Patterns
- Method: `apiRequest(endpoint, method, data)` → `fetch('/api/index.php/' + endpoint, ...)`
- Response: `{ status: 'success'|'error', data: ..., message: '...' }`
- Auth: Bearer JWT token in Authorization header (or httpOnly cookie)
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
- Run via `run-migrations.sh` from `code/` directory
- Run migrations in numerical order
- Guard clauses (`IF NOT EXISTS`, `@col_exists`) for idempotent re-runs

---

## File Map Summary

### Custom Controllers — `code/customizations/api/Controllers/`
| File | Routes |
|------|--------|
| `BranchesController.php` | branches CRUD + active + summary |
| `SellersController.php` | sellers CRUD + search + blacklist + history + photo |
| `PurchaseOrdersController.php` | purchase-orders CRUD + cancel |
| `PurchaseItemCatalogController.php` | catalog CRUD + search + update-category + price-board |
| `SaleLotsController.php` | sale-lots CRUD + confirm + cancel + record-revenue |
| `PriceTiersController.php` | price-tiers CRUD per category + create catalog item |
| `StockTransfersController.php` | stock-transfers CRUD + confirm + cancel |
| `PhotoUploadController.php` | purchase-orders photo upload/list + photo-token |
| `FinancialController.php` | financial summary + lot-revenues + purchase-by-category + expenses + export |
| `ItemConditionsController.php` | (deprecated) conditions list |

### Custom Models — `code/customizations/api/Models/`
| File | Table |
|------|-------|
| `Branch.php` | branches |
| `Seller.php` | sellers |
| `PurchaseOrder.php` | purchase_orders + items + FIFO cost |
| `PurchaseItemCatalog.php` | purchase_item_catalog |
| `SaleLot.php` | sale_lots + items + FIFO cost |
| `StockTransfer.php` | stock_transfers |
| `BusinessExpense.php` | business_expenses |
| `ItemCondition.php` | item_conditions |

### Key Helper Files
| File | Role |
|------|------|
| `base-pos/assets/js/config.js` | `window.apiPath = '/api/index.php'` |
| `base-pos/assets/js/common.js` | `apiRequest()`, `formatCurrency()`, `showNotification()` |
| `base-pos/assets/js/login.js` | Login page |
| `base-pos/assets/css/styles.css` | All CSS custom properties + component styles |
| `base-pos/api/autoload.php` | PSR-4 autoloader for both base-pos and customizations |
| `base-pos/api/Router.php` | Route registration + dispatch + auth |
| `base-pos/api/Services/ReportService.php` | Report engine |
| `base-pos/api/Services/TokenService.php` | JWT token handling |
| `base-pos/api/Services/BackupService.php` | Backup/restore logic |
| `base-pos/api/Services/ValidationService.php` | Input validation helpers |

---

## Quick Reference

### Server URL
- Admin: `http://localhost:8080/admin/`
- API: `http://localhost:8080/api/index.php/`
- phpMyAdmin: `http://localhost:8081/`
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

### Run Tests
```bash
cd tests/api
bash run.sh          # 9 test functions, 54 assertions
bash run.sh auth sellers  # Run specific test groups
```
