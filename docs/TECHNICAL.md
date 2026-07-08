# Technical Documentation — Secondhand POS

**Version:** 1.0 | **Last Updated:** 2026-07-07 | **PHP 8.2 + MySQL 8.0 + Vanilla JS**

---

## 1. Architecture Overview

### 1.1 Two-Layer System

```
HTTP Request → Docker (Apache) → index.php → Router → Controller → Model → DB
                                                                     ↓
                                                              JSON Response
```

The system uses a **two-layer overlay architecture**:

```
code/
├── base-pos/                  # Core framework — DO NOT EDIT unless patching
│   └── api/
│       ├── index.php          # Entry point (try/catch → JSON error)
│       ├── config.php         # DB + JWT config from env
│       ├── autoload.php       # PSR-4 loader (scans both layers)
│       ├── Router.php         # Route registry (ALL routes here)
│       ├── Core/              # Database, Auth, Response, Model, Logger
│       ├── Models/            # Built-in: Inventory, Product, User, etc.
│       └── Controllers/       # Built-in: Auth, Reports, Users, etc.
│
└── customizations/            # Custom code — EDIT HERE
    ├── api/
    │   ├── Controllers/       # 11 controllers
    │   ├── Models/            # 10 models
    │   └── Services/          # ReportService (14 methods)
    └── database/
        └── migrations/        # 48 files (001-048)
```

### 1.2 Request Lifecycle

1. **Docker** (Apache) receives HTTP request
2. **index.php** sets headers (CORS, JSON), catches exceptions
3. **Router** parses URI → matches route table → extracts `?id=` param
4. **Router::checkAuth()** validates JWT (cookie → Bearer)
5. **Controller** method executes (validates input, enforces branch scope, delegates to Model)
6. **Model** interacts with DB (PDO prepared statements, transactions)
7. **Response::success()** / **Response::error()** returns JSON

### 1.3 File Counts

| Component | Count | Location |
|-----------|-------|----------|
| Custom controllers | 11 | `customizations/api/Controllers/` |
| Custom models | 10 | `customizations/api/Models/` |
| Core controllers | 4 | `base-pos/api/Controllers/` |
| Core models | 8 | `base-pos/api/Models/` |
| Core services | 2 | `base-pos/api/Core/` |
| Custom services | 1 | `customizations/api/Services/` |
| Migrations | 48 | `customizations/database/migrations/` |
| API tests | 12 | `code/tests/api/` |

---

## 2. API Reference

### 2.1 Base URL

```
http://localhost:8080/api/index.php/
```

### 2.2 Authentication

#### POST `/auth/login`
Authenticate user. Sets httpOnly cookie `posToken` + returns user data.

```json
// Request
{ "username": "admin", "password": "admin" }

// Response
{ "status": "success", "data": { "user": {...}, "token": "eyJ..." } }
```

#### GET/POST `/auth/verify`
Verify current session.

#### POST `/auth/logout`
Logout (clears cookie + blocklists token).

### 2.3 Branches

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/branches` | admin | All branches |
| GET | `/branches/active` | any | Active branches only |
| GET | `/branches/summary` | any | Branch summary (dashboard) |
| GET | `/branches/branch?id=` | any | Single branch |
| PUT | `/branches/branch?id=` | admin | Update branch |
| POST | `/branches` | admin | Create branch |

### 2.4 Sellers

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/sellers` | any | List sellers (supports `include_blacklisted=true`) |
| POST | `/sellers` | manager+ | Create seller |
| GET | `/sellers/search?q=` | any | Search by name/ID card |
| GET | `/sellers/seller?id=` | any | Get single seller |
| PUT | `/sellers/seller?id=` | manager+ | Update seller |
| POST | `/sellers/blacklist?id=` | manager+ | Blacklist seller (requires `reason`) |
| POST | `/sellers/unblacklist?id=` | manager+ | Unblacklist seller |
| GET | `/sellers/history?id=` | any | Seller purchase history |
| GET | `/sellers/data-center?id=` | any | Seller datacenter (summary + all data) |
| POST | `/sellers/photo?id=` | any | Upload seller photo |

### 2.5 Purchase Orders

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/purchase-orders` | any | List POs (filters: `branch_id`, `status`, `seller_id`, `date_from`, `date_to`) |
| POST | `/purchase-orders` | manager+ | Create PO (with items) |
| GET | `/purchase-orders/order?id=` | any | Get single PO + items |
| GET | `/purchase-orders/print?id=` | any | Get PO formatted for printing |
| POST | `/purchase-orders/cancel?id=` | admin/manager | Cancel PO (restores stock) |

Create PO request:
```json
{
  "branch_id": 1,
  "seller_id": 1,
  "items": [
    { "catalog_id": 1, "category_id": 1, "item_name": "ทองแดง", "quantity_kg": 5.0, "unit_price": 120.0, "price_tier": "บิล1", "weight_deduction": 0.0 }
  ],
  "idempotency_key": "uuid-here"
}
```

### 2.6 Sale Lots

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/sale-lots` | any | List lots (filters: `branch_id`, `status`, `date_from`, `date_to`) |
| POST | `/sale-lots` | manager+ | Create lot (**confirmed immediately**) |
| GET | `/sale-lots/sale-lot?id=` | any | Get lot + items + profit breakdown |
| PUT | `/sale-lots/sale-lot?id=` | manager+ | Update lot |
| DELETE | `/sale-lots/sale-lot?id=` | manager+ | Delete lot (draft only) |
| POST | `/sale-lots/confirm?id=` | manager+ | Confirm lot (draft→confirmed, deducts stock) |
| POST | `/sale-lots/cancel?id=` | manager+ | Cancel lot (confirmed→cancelled, restores stock) |
| POST | `/sale-lots/record-revenue?id=` | manager+ | Record actual revenue |

Create sale lot request:
```json
{
  "branch_id": 1,
  "buyer_name": "ห้างทองสุรินทร์",
  "sale_date": "2026-07-07",
  "transport_cost": 500,
  "items": [
    { "catalog_id": 1, "category_id": 1, "item_name": "ทองแดง", "quantity_kg": 100, "unit_price": 150 }
  ],
  "idempotency_key": "uuid-here"
}
```

Sale lot response includes:
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "reference_no": "SO-SR1-20260707-001",
    "total_amount": 15000,
    "total_cost": 12000,
    "profit_breakdown": {
      "total_amount": 15000,
      "total_cost": 12000,
      "total_expenses": 500,
      "profit": 3000,
      "net_profit": 2500,
      "margin_pct": 20,
      "net_margin_pct": 16.67
    }
  }
}
```

### 2.7 Purchase Catalog

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/purchase-catalog` | any | List catalog |
| POST | `/purchase-catalog` | manager+ | Create item |
| GET | `/purchase-catalog/search?q=` | any | Search catalog |
| GET | `/purchase-catalog/item?id=` | any | Get item |
| PUT | `/purchase-catalog/item?id=` | manager+ | Update item |
| DELETE | `/purchase-catalog/item?id=` | manager+ | Delete item |
| POST | `/purchase-catalog/update-category?id=` | manager+ | Update item category |
| GET | `/purchase-catalog/price-board` | any | Price board for all branches |

### 2.8 Stock Transfers

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/stock-transfers` | admin | List transfers |
| POST | `/stock-transfers` | admin | Create transfer |
| POST | `/stock-transfers/confirm?id=` | admin | Confirm (deducts source, adds destination) |
| POST | `/stock-transfers/cancel?id=` | admin | Cancel |

### 2.9 Financial

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/financial/summary` | any | Financial summary (branch stats) |
| GET | `/financial/lot-revenues` | any | Lot revenues |
| GET | `/financial/purchase-by-category` | any | Purchases grouped by category |
| GET | `/financial/expenses` | any | List business expenses |
| POST | `/financial/expenses` | any | Create expense |
| DELETE | `/financial/expenses?id=` | any | Delete expense |
| GET | `/financial/export` | any | Export financial CSV |

### 2.10 Employees

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/employees` | any | List employees |
| POST | `/employees` | manager+ | Create employee |
| GET | `/employees/employee?id=` | any | Get employee |
| PUT | `/employees/employee?id=` | manager+ | Update employee |
| DELETE | `/employees/employee?id=` | manager+ | Delete employee |
| POST | `/employees/salary-expense?id=` | manager+ | Create salary expense |
| POST | `/employees/sso-expense?id=` | manager+ | Create SSO expense |

### 2.11 Reports (Core)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/reports/dashboard-stats` | Dashboard stat cards |
| GET | `/reports/sales-chart` | Sales chart data |
| GET | `/reports/recent-sales` | Recent retail sales |
| GET | `/reports/purchase-chart` | Purchase chart data |
| GET | `/reports/recent-purchases` | Recent purchases |
| GET | `/reports/recent-sale-lots` | Recent sale lots (dashboard) |
| GET | `/reports/purchase-report` | Purchase report (with CSV export) |
| GET | `/reports/sale-lot-report` | Sale lot report (with CSV export) |
| GET | `/reports/sale-lot-chart` | Sale lot chart data |
| GET | `/reports/inventory-report` | Current inventory report |
| GET | `/reports/cashier-performance` | Cashier performance report |
| GET | `/reports/tax-report` | Tax report |

### 2.12 Users & Settings

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/users/all` | admin | All users |
| POST | `/users` | admin | Create user |
| GET | `/users/user?id=` | admin | Get user |
| PUT | `/users/user?id=` | admin | Update user |
| DELETE | `/users/user?id=` | admin | Delete user |
| POST | `/users/change-password?id=` | admin | Change any user's password |
| GET | `/users/activity-log` | admin | Activity log |
| POST | `/settings/store` | admin | Save store settings |
| GET | `/settings/store` | any | Get store settings |
| POST | `/settings/system` | admin | Save system settings |
| GET | `/settings/system` | admin | Get system settings |
| POST | `/settings/backup/create` | admin | Create DB backup |
| POST | `/settings/backup/restore` | admin | Restore DB backup |
| GET | `/settings/backup/history` | admin | Backup history |
| POST | `/settings/backup/delete` | admin | Delete backup |

### 2.13 Response Format

All endpoints return uniform JSON:

```json
// Success
{ "status": "success", "data": { ... }, "message": "ดำเนินการสำเร็จ" }

// Error
{ "status": "error", "message": "รายละเอียดข้อผิดพลาด" }
```

HTTP status codes:
- `200` — Success
- `400` — Validation error
- `401` — Authentication required
- `403` — Forbidden (wrong branch / role)
- `404` — Resource not found
- `429` — Rate limited
- `500` — Server error

---

## 3. Database Schema

### 3.1 Entity Relationship

```
branches ──┬── purchase_orders ──┬── purchase_order_items
           │                     └── purchase_order_photos
           ├── sale_lots ────────┬── sale_lot_items
           ├── categories ───────┼── purchase_item_catalog
           ├── sellers ──────────┘
           ├── stock_transfers
           ├── business_expenses
           └── employees
```

### 3.2 Core Tables

| Table | Rows | Purpose |
|-------|------|---------|
| `branches` | 4 | Branch configuration (code, name, cost_method) |
| `sellers` | ~24 | Sellers (id_card, phone, vehicle_plate, blacklist, pdpa_consent) |
| `categories` | 13 | Product categories (stock_kg, alert_threshold, requires_precious_receipt) |
| `purchase_orders` | ~72 | Purchase receipts (reference_no, status, amounts) |
| `purchase_order_items` | ~53 | PO line items (quantity, unit_price, consumed_qty, fifo_cost) |
| `sale_lots` | ~13 | Sale lots (reference_no, amounts, profit, actual_revenue) |
| `sale_lot_items` | ~13 | Sale lot line items (quantity, unit_price, fifo_cost) |
| `purchase_item_catalog` | 21 | Master catalog (tier_prices JSON, category FK) |
| `stock_transfers` | 0 | Cross-branch stock transfers |
| `business_expenses` | 4 | Operating expenses |
| `employees` | 5 | Employee records |
| `users` | 1 | System users |

### 3.3 Key Indexes

| Table | Index | Columns |
|-------|-------|---------|
| `purchase_order_items` | `idx_poi_category_consumed` | `category_id, consumed_qty` (FIFO query) |
| `purchase_orders` | `idx_po_branch_status_date` | `branch_id, status, created_at` (dashboard) |
| `sale_lots` | `idx_sale_lots_created_at` | `created_at` (dashboard ORDER BY) |
| `sale_lots` | `idx_sale_lots_branch_id` | `branch_id` |
| `sellers` | `idx_sellers_full_name` | `full_name` (search) |
| `sellers` | `idx_id_card` | `id_card` (unique + search) |

Total: **81 indexes** across 29 tables.

### 3.4 Migration Files

48 migrations in `customizations/database/migrations/001-048`:
- 001-010: Core tables (branches, sellers, POs, sale lots, catalog)
- 011-020: Indexes + atomic stock column + JSON expenses
- 021-030: Cost methods, stock transfers, catalog improvements
- 031-040: Business expenses, precious metals, employees, security
- 041-048: Idempotency, PDPA, seller search indexes, performance index

---

## 4. Security Model

### 4.1 Authentication

```
Login → bcrypt(password) → compare hash → generate JWT
         ↓
    Set httpOnly cookie: posToken
    Return Bearer token in body
```

Token validation flow:
1. `Router::checkAuth()` checks public routes whitelist
2. Reads `posToken` cookie (httpOnly, not accessible by JS)
3. Falls back to `Authorization: Bearer` header
4. Validates JWT signature + expiry via `TokenService::validate()`
5. Stores decoded user data in `$this->user`

### 4.2 Authorization

JWT payload:
```json
{
  "user_id": 1,
  "username": "admin",
  "role": "admin",         // admin / manager / cashier
  "branch_id": null        // null = global (admin only)
}
```

Role enforcement:
- **Admin** — full access to all branches
- **Manager** — branch-scoped (enforced in every controller method)
- **Cashier** — read + create PO only

Branch scope enforcement pattern:
```php
if (($this->user['role'] ?? '') !== 'admin') {
    $userBranch = $this->user['branch_id'] ?? null;
    if (!$userBranch || (int)$data['branch_id'] !== (int)$userBranch) {
        Response::error('ไม่มีสิทธิ์', 403);
    }
}
```

### 4.3 Input Validation

- **All IDs** — `intval()` after extracting from query/path
- **SQL** — PDO prepared statements everywhere (no concatenation)
- **Branch ID** — user input vs JWT comparison (non-admin)
- **Seller** — 13-digit ID card, 10-digit phone validation
- **Items** — quantity > 0, required fields check
- **Blacklist** — blocked sellers cannot create POs

### 4.4 Rate Limiting

- **Login attempts**: IP-based tracking in `login_attempts` table
- **Threshold**: 5 failed attempts → 15-minute lockout
- **Cleanup**: Old records auto-deleted

### 4.5 JWT Revocation

- **Token blocklist** (`token_blocklist` table)
- When user logs out, hash of current token is stored
- Validated on every request
- Blocklist auto-cleans expired tokens

### 4.6 Race Safety (FIFO)

Sale Lot confirm/cancel uses:
1. `SELECT ... FOR UPDATE` — locks row (TOCTOU protection)
2. Atomic conditional UPDATE:
   ```sql
   UPDATE purchase_order_items
   SET consumed_qty = consumed_qty + ?
   WHERE id = ? AND consumed_qty + ? <= quantity
   ```
3. `rowCount()` check — if 0, another process consumed the stock

### 4.7 CORS

Configured via `ALLOWED_ORIGINS` env var:
```php
header('Access-Control-Allow-Origin: ' . $allowedOrigins);
header('Access-Control-Allow-Credentials: true');
```

---

## 5. Business Logic

### 5.1 Purchase Order → Stock Flow

```
PO Created:
  1. BEGIN TRANSACTION
  2. Generate reference_no
  3. INSERT purchase_orders
  4. INSERT purchase_order_items (per item)
  5. UPDATE categories SET stock_kg = stock_kg + netQty
  6. UPDATE sellers SET total_transactions += 1, total_amount += total
  7. COMMIT

PO Cancelled:
  1. BEGIN TRANSACTION
  2. UPDATE categories SET stock_kg = GREATEST(0, stock_kg - netQty)
  3. UPDATE purchase_orders SET status = 'cancelled'
  4. UPDATE sellers SET total_transactions -= 1, total_amount -= total
  5. COMMIT
```

### 5.2 Sale Lot → FIFO Cost → Stock Deduction

```
Sale Lot Created (confirmed):
  1. BEGIN TRANSACTION
  2. For each item: calculateFifoCost() or calculateWeightedAvgCost()
  3. INSERT sale_lots, sale_lot_items
  4. deductStock() → for each item:
       a. UPDATE categories SET stock_kg = GREATEST(0, stock_kg - qty)
       b. SELECT PO items with remaining stock (oldest first) FOR UPDATE
       c. Atomic UPDATE: consumed_qty += take (with rowCount check)
  5. COMMIT

Sale Lot Cancelled:
  1. BEGIN TRANSACTION (FOR UPDATE lock)
  2. UPDATE status = 'cancelled'
  3. restoreStock() → for each item:
       a. UPDATE categories SET stock_kg = stock_kg + qty
       b. SELECT PO items with consumed stock (oldest first) FOR UPDATE
       c. Atomic UPDATE: consumed_qty -= take
  4. COMMIT
```

### 5.3 Cost Methods

**FIFO** (default):
```sql
SELECT poi.id, poi.quantity, poi.unit_price, poi.consumed_qty
FROM purchase_order_items poi
JOIN purchase_orders po ON poi.purchase_order_id = po.id
WHERE po.branch_id = ? AND poi.category_id = ?
  AND po.status = 'completed' AND (poi.quantity - poi.consumed_qty) > 0
ORDER BY po.created_at ASC
```

**Weighted Average**:
```sql
SELECT COALESCE(SUM(poi.quantity - poi.consumed_qty), 0) AS total_qty,
       COALESCE(SUM((poi.quantity - poi.consumed_qty) * poi.unit_price), 0) AS total_value
FROM purchase_order_items poi
JOIN purchase_orders po ON poi.purchase_order_id = po.id
WHERE po.branch_id = ? AND poi.category_id = ?
  AND po.status = 'completed'
```

---

## 6. Frontend Architecture

### 6.1 Stack
- **Vanilla JavaScript ES6** (no React, no Vue, no jQuery)
- **CSS** — Custom properties (2469 lines, monolithic)
- **Font** — IBM Plex Sans Thai (Google Fonts)
- **Icons** — Icomoon custom icon font

### 6.2 Key JS Files

| File | Path | Purpose |
|------|------|---------|
| `config.js` | `assets/js/config.js` | API base URL (`window.apiPath`) |
| `common.js` | `assets/js/common.js` | `apiRequest()`, `showNotification()`, `formatCurrency()` |
| `animations.js` | `assets/js/animations.js` | IntersectionObserver scroll animations |

### 6.3 JS API Helper

```javascript
// All API calls go through this helper
async function apiRequest(endpoint, method = 'GET', data = null) {
    const options = {
        method,
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
    };
    if (data) options.body = JSON.stringify(data);

    const res = await fetch(window.apiPath + endpoint, options);
    return res.json();
}
```

### 6.4 CSS Architecture

Design tokens via CSS custom properties (`:root`):
- Colors: `--color-primary` (#D97706 amber)
- Shadows: warm-tinted (not grey)
- Typography: `--font-main` (IBM Plex Sans Thai)
- Spacing: 4px-based scale (1-5)
- Z-index: controlled stack (100-9999)

---

## 7. Performance

### 7.1 Benchmark Results (2026-07-07)

| Metric | Value |
|--------|-------|
| GET endpoints avg | < 15ms |
| GET endpoints p95 | < 60ms |
| 5× concurrency (any endpoint) | 10-21ms |
| Login (bcrypt + JWT) | 309ms avg |
| Docker web container | 59MB RAM |
| Docker db container | 409MB RAM |

### 7.2 Query Performance

All critical queries have composite indexes:
- Dashboard: `idx_po_branch_status_date` (branch_id, status, created_at)
- FIFO: `idx_poi_category_consumed` (category_id, consumed_qty)
- Seller search: `idx_sellers_full_name`, `idx_id_card`
- Sale lots listing: `idx_sale_lots_created_at`, `idx_sale_lots_branch_id`

---

## 8. Testing

### 8.1 Test Suite

12 test files in `code/tests/api/`, run via bash/curl:

| Group | Tests | What It Covers |
|-------|-------|----------------|
| `test_auth` | 6 | Login, verify, invalid tokens |
| `test_branches` | 3 | Branch listing |
| `test_sellers` | 28 | CRUD, search, blacklist, history, data center |
| `test_purchase_orders` | 14 | CRUD, cancel, print, edge cases |
| `test_sale_lots` | 3 | CRUD, status transitions |
| `test_sale_lots_fifo` | 15 | Full FIFO lifecycle with stock validation |
| `test_catalog` | 3 | Catalog CRUD + search |
| `test_inventory` | 3 | Category/product listing |

Total: **84 tests**, **all passing**.

### 8.2 CI Pipeline

`.github/workflows/test.yml`:
1. PHP lint (syntax check)
2. Run migrations on test DB
3. Start PHP built-in server
4. Run all tests
5. Upload test report as artifact

---

## 9. Deployment

### 9.1 Docker Architecture

```
┌─────────────────┐     ┌──────────────────┐
│  scrap-pos-web  │     │  scrap-pos-db    │
│  PHP 8.2 Apache │────▶│  MySQL 8.0       │
│  Port 8080:80   │     │  Port 3307:3306  │
└─────────────────┘     └──────────────────┘
         │
         ▼
┌─────────────────┐
│  Caddy (optional)│
│  HTTPS + TLS    │
│  Port 443       │
└─────────────────┘
```

### 9.2 Health Check

Web container health check:
```
URL:     http://localhost/api/index.php/branches
Period:  30s
Retries: 3
Timeout: 10s
```

### 9.3 Deploy Script

`code/deploy.sh`:
1. `docker exec` mysql backup (mysqldump)
2. `git pull` latest code
3. `docker compose up -d --build web`
4. Wait for health check (up to 30s, 3 retries)
5. Run migrations inside container
6. Rollback on failure (restore previous image)

---

## 10. Appendix

### 10.1 Environmental Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `JWT_SECRET` | Yes | — | JWT signing secret (change in production) |
| `MYSQL_ROOT_PASSWORD` | Yes | — | MySQL root password |
| `MYSQL_USER` | Yes | `pos_user` | Application DB user |
| `MYSQL_PASSWORD` | Yes | — | Application DB password |
| `MYSQL_DATABASE` | Yes | `pos_system` | Database name |
| `APP_ENV` | No | `development` | Set to `production` in production |
| `DOMAIN` | No | — | Domain for Caddy HTTPS |
| `ACME_EMAIL` | No | — | Email for Let's Encrypt |
| `ALLOWED_ORIGINS` | No | `*` | CORS allowed origins |

### 10.2 Error Codes

| Code | Meaning | Action |
|------|---------|--------|
| `AUTH_REQUIRED` | Not logged in | Redirect to login |
| `INVALID_TOKEN` | Token expired/revoked | Re-login |
| `FORBIDDEN` | No permission for this branch | Contact admin |
| `VALIDATION_ERROR` | Invalid input | Fix request data |
| `STOCK_INSUFFICIENT` | Not enough inventory | Check stock levels |
| `DUPLICATE_ENTRY` | Duplicate ID card/phone | Use different value |
| `IDEMPOTENCY_CONFLICT` | Duplicate request | Already processed |

### 10.3 File Naming Conventions

| Layer | Convention | Example |
|-------|-----------|---------|
| Routes | `kebab-case/action` | `sale-lots/confirm` |
| Tables | `snake_case` plural | `purchase_order_items` |
| PHP classes | `PascalCase` | `PurchaseOrdersController` |
| PHP methods | `camelCase` | `createPurchaseOrder()` |
| JS IDs | `camelCase` | `sellerSearchInput` |
| Migrations | `NNN_description.sql` | `048_add_sale_lots_created_at_index.sql` |

---

*Built for SoloCorp OS by Dr.solodev | Technical Documentation v1.0*
