# Scrap POS — Code Project

**ระบบจัดการร้านรับซื้อของเก่า 4 สาขา | PHP 8.2 + MySQL 8.0 + Docker**

---

## Quick Start

```bash
# Copy env config (edit secrets)
cp .env.example .env

# Start all containers
docker compose up -d

# Services ready at:
# - Admin:   http://localhost:8080/admin/  (admin / admin)
# - API:     http://localhost:8080/api/index.php/
# - phpMyAdmin: http://localhost:8081/
```

**Prerequisites:** Docker, Docker Compose

---

## โครงสร้างโปรเจกต์

```
code/
├── base-pos/                       # ฐาน goragodwiriya/pos-system (แก้ไขเมื่อจำเป็น)
│   ├── api/                        # Backend PHP
│   │   ├── Router.php              # ALL routes registered (core + custom)
│   │   ├── index.php               # Entry point (catch Exception → JSON)
│   │   ├── config.php              # DB config, JWT_SECRET from env
│   │   ├── autoload.php            # PSR-4 autoloader (base-pos + customizations)
│   │   ├── Core/                   # Database, Auth, Response, Model, Logger
│   │   ├── Models/                 # Built-in: Inventory, Product, Sale, Category, User...
│   │   └── Controllers/            # Built-in: Auth, Inventory, Sales, Users, Reports...
│   ├── admin/                      # Admin pages (PHP + Vanilla JS) — active target
│   ├── pos/                        # POS terminal
│   └── assets/                     # CSS, JS (common.js, config.js)
│
└── customizations/                 # Custom overlay (loaded by autoloader)
    ├── api/
    │   ├── Models/                 # Branch, Seller, PurchaseOrder, SaleLot, PurchaseItemCatalog
    │   ├── Controllers/            # Branches, Sellers, PurchaseOrders, SaleLots, PriceTiers...
    │   └── Services/
    │       └── ReportService.php   # Report engine (purchase/sale-lot/chart)
    └── database/
        ├── migrations/             # 021 migrations (001-021, numbered sequentially)
        └── run-migrations.sh
```

---

## Docker Setup

### Containers

| Service | Container Name | Port | Image |
|---------|---------------|------|-------|
| Web (Apache + PHP 8.2) | `scrap-pos-web` | 8080:80 | `php:8.2-apache` |
| Database (MySQL 8.0) | `scrap-pos-db` | 3307:3306 | `mysql:8.0` |
| phpMyAdmin | `scrap-pos-pma` | 8081:80 | `phpmyadmin:latest` |

### Environment Variables (`.env`)

```
JWT_SECRET=your_jwt_secret_here
MYSQL_ROOT_PASSWORD=rootpass
MYSQL_USER=pos_user
MYSQL_PASSWORD=userpass
MYSQL_DATABASE=pos_system
PMA_USER=root
PMA_PASSWORD=rootpass
ALLOWED_ORIGINS=http://localhost:8080,http://localhost:3000
```

### Container Management

```bash
# Start
docker compose up -d

# Rebuild (after Dockerfile changes)
docker compose up -d --build

# Reset everything (delete DB, start fresh)
docker compose down -v && docker compose up -d --build

# View logs
docker compose logs -f web

# MySQL CLI
docker exec -it scrap-pos-db mysql -uroot -prootpass pos_system
```

---

## Database

### Migration Strategy
- **DO NOT** modify `base-pos/database/pos_system.sql`
- All changes → `customizations/database/migrations/NNN_description.sql`
- Run in numerical order
- Auto-executed on first container start via `docker/mysql-init.sh`

### Tables (21 migrations)

| Migration | Table(s) | Purpose |
|-----------|----------|---------|
| 001 | `branches` | สาขา (code, address, cost_method from 021) |
| 002 | `sellers` | ผู้ขาย (id_card, phone, vehicle_plate from 011) |
| 003 | `item_conditions` | (deprecated — replaced by weight_deduction) |
| 004 | `purchase_orders`, `purchase_order_items` | ใบรับซื้อ |
| 005 | — | Seed default categories (11 types) |
| 006 | — | Demo data |
| 007 | `price_tiers` | ราคา 3 ระดับต่อ category |
| 008 | ALTER `purchase_order_items` | ADD price_tier column |
| 009 | `sale_lots`, `sale_lot_items` | ขาย Lot (profit GENERATED column) |
| 010 | ALTER `purchase_order_items` | ADD consumed_qty, fifo_cost |
| 011 | ALTER `sellers` | ADD vehicle_plate |
| 012 | `purchase_item_catalog` | Master catalog (code, name, category, tier_prices JSON) |
| 013 | ALTER `purchase_order_items` | REPLACE condition_id → weight_deduction |
| 014 | INDEXES | FK + LIKE search indexes |
| 015 | ALTER `purchase_item_catalog` | ADD price_tier1/2/3 columns |
| 016 | ALTER `purchase_item_catalog` | ADD tier_prices JSON column |
| 017 | ALTER `sale_lots` | ADD expenses JSON |
| 018 | RENAME | Fix duplicate 017, labels → "บิล1/2/3" |
| 019 | — | Placeholder (no-op) |
| 020 | INDEXES + ATOMIC | FIFO indexes + atomic conditional UPDATE for consumed_qty |
| 021 | ALTER `branches` | ADD cost_method ENUM('fifo','weighted') default 'fifo' |

---

## Key Design Points

### Two-layer Architecture
- **base-pos:** Core system files — patched in-place when necessary
- **customizations:** Custom PHP code loaded by autoloader — Models, Controllers, Services, migrations
- **Autoloader** (`base-pos/api/autoload.php`) scans both directories

### Business Flows

| Flow | Page | Purpose |
|------|------|---------|
| รับซื้อ | `purchase-orders.html` | Buy scrap from individual sellers |
| ขาย Lot | `sale-lots.html` | Sell bulk lots to collection centers |
| ขายปลีก | `pos/index.html` + `sales.html` | Retail sales (minor flow) |

### Cost Methods
- **FIFO (default):** Consume oldest PO items first, atomic conditional UPDATE for race safety
- **Weighted Average:** Per-branch setting (`branches.cost_method`), simpler costing
- TOCTOU protection: `SELECT ... FOR UPDATE` in confirm/cancel transactions

---

## Testing

```bash
cd tests/api
bash run.sh
```

**47 tests** (bash/curl, zero dependencies):

| Test File | Tests | What It Covers |
|-----------|-------|----------------|
| `test_auth.sh` | 3 | Login, verify, invalid token |
| `test_branches.sh` | 6 | List, active, summary, CRUD |
| `test_sellers.sh` | 8 | CRUD, search, blacklist |
| `test_purchase_orders.sh` | 9 | CRUD, cancel, edge cases |
| `test_sale_lots.sh` | 8 | CRUD, draft/confirm/cancel |
| `test_sale_lots_fifo.sh` | 13 | Full FIFO: create PO → lot → confirm → cancel → reject re-confirm |
| `test_catalog.sh` | 6 | CRUD, search by code/name |
| `test_price_tiers.sh` | 5 | CRUD, update categories |

### CI Pipeline (GitHub Actions)
`.github/workflows/test.yml`: PHP lint → start server → run 47 tests

---

## API

### Custom Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/branches`, `/branches/active`, `/branches/summary` | Branch info |
| GET | `/sellers`, `/sellers/search?q=` | Seller list/search |
| POST | `/sellers` | Create seller |
| PUT | `/sellers/seller?id=` | Update seller |
| POST | `/sellers/blacklist`, `/unblacklist` | Blacklist mgmt |
| GET | `/purchase-orders` | List POs (filters) |
| POST | `/purchase-orders` | Create PO |
| GET | `/purchase-orders/order?id=` | Get single PO |
| POST | `/purchase-orders/cancel?id=` | Cancel PO |
| GET | `/purchase-catalog`, `/purchase-catalog/search?q=` | Catalog |
| POST | `/purchase-catalog` | Create catalog item |
| PUT | `/purchase-catalog/item?id=` | Update catalog item |
| GET | `/sale-lots` | List sale lots (filters) |
| POST | `/sale-lots` | Create sale lot |
| GET | `/sale-lots/sale-lot?id=` | Get single lot |
| PUT | `/sale-lots/sale-lot?id=` | Update (draft only) |
| DELETE | `/sale-lots/sale-lot?id=` | Delete (draft only) |
| POST | `/sale-lots/confirm?id=` | Confirm → deduct stock |
| POST | `/sale-lots/cancel?id=` | Cancel → restore stock |
| GET | `/price-tiers` | List categories with tiers |
| PUT | `/price-tiers/category?id=` | Update category tiers |
| GET | `/reports/purchase-report` | Purchase report + CSV |
| GET | `/reports/sale-lot-report` | Sale lot report + CSV |
| GET | `/reports/sale-lot-chart` | Chart data |
| GET | `/reports/recent-sale-lots` | Recent lots for dashboard |

### API Pattern

```javascript
// Request (via common.js helper)
const res = await apiRequest('sale-lots', 'POST', payload);

// Response format
{ status: 'success'|'error', data: {...}, message: '...' }
```

### Route Registration
```php
$this->routes[] = ['route' => 'resource/action', 'controller' => 'FooController', 'method' => 'bar', 'verb' => 'GET'];
```

---

## Security

- **JWT Authentication** — Bearer token, role-based (admin/manager/cashier), branch-scoped
- **Secrets in `.env`** — JWT_SECRET, DB credentials, PMA credentials (all gitignored)
- **CORS** — Configured via `ALLOWED_ORIGINS` env var
- **Input Validation** — Price, category, seller fields validated
- **SQL Injection** — PDO prepared statements throughout
- **Race Condition** — Atomic conditional UPDATE + FOR UPDATE locking

---

## Known Issues

- `item-conditions` deprecated but old UI still references it
- `catch (Exception $e)` → should be `catch (\Throwable $e)` for PHP 8 TypeError safety
- No HTTPS, no rate limiting on auth endpoint

---

*Built for SoloCorp OS by Dr.solodev*
