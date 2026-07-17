# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Secondhand POS — junk shop / scrap-buying POS system (รับซื้อของเก่า) for a 4-branch business in Surin, Thailand. Customized from [goragodwiriya/pos-system](https://github.com/goragodwiriya/pos-system).

Three business flows in one system: Purchase Orders (buying from sellers, daily/high-volume), Sale Lots (selling bulk to factories, infrequent/high-value), and retail POS/Sales.

## Commands

All commands run from the `code/` directory unless noted.

```bash
# Setup / run
cp .env.example .env          # set JWT_SECRET, DB_PASS, MYSQL_ROOT_PASSWORD, MYSQL_PASSWORD
docker compose up -d           # web (8080) + db (3307)
docker compose --profile caddy up -d   # + Caddy reverse proxy (production only)
docker compose down -v --remove-orphans && docker compose up -d --build  # full reset

# Database migrations (after schema changes)
cd customizations/database
./run-migrations.sh [db_user] [db_password]   # defaults: root / (empty)

# Tests — bash API test suite (84 tests)
cd tests/api
bash run.sh                                   # run everything
bash run.sh auth sellers                      # run specific groups (test_auth, test_sellers, etc.)
API_BASE=http://other:8080/api/index.php bash run.sh   # against a different server

# Tests — PHPUnit (Unit + Integration, run from code/)
composer install    # if vendor/ missing
phpunit              # uses phpunit.xml (bootstrap: base-pos/api/autoload.php)
phpunit tests/Unit/FifoCalculationTest.php   # single file

# PHP syntax check (what CI runs before tests)
find base-pos/api -name '*.php' -exec php -l {} \;
find customizations/api -name '*.php' -exec php -l {} \;

# Deploy (production, from code/)
bash deploy.sh       # backup DB -> git pull -> rebuild containers -> migrate -> healthcheck -> rollback on failure
```

CI (`.github/workflows/test.yml`) does: PHP lint -> load `base-pos/database/pos_system.sql` -> run all migrations -> start `php -S` -> run `tests/api/run.sh`.

## Architecture

### Two-layer overlay

```
code/
├── base-pos/            # Core POS framework, patched in-place (upstream: goragodwiriya/pos-system)
│   ├── api/             # Router.php, Controllers/, Models/, Core/, Services/, autoload.php, config.php
│   ├── admin/            # Admin UI pages (primary delivery target)
│   ├── mobile/           # Standalone mobile pages (e.g. purchase.html — 4-step PO wizard)
│   ├── pos/              # POS terminal
│   └── assets/           # CSS + vanilla JS (no framework, no jQuery)
└── customizations/       # Custom overlay, loaded via autoloader
    ├── api/Models/        # Custom models (Branch, PurchaseOrder, SaleLot, Seller, StockTransfer, ...)
    ├── api/Controllers/    # Custom controllers (SaleLotsController, StockTransfersController, ...)
    └── database/
        ├── migrations/     # Numbered SQL migrations (currently 001-050)
        └── run-migrations.sh
```

`base-pos/api/autoload.php` registers both `base-pos/api/{Core,Controllers,Models,Services}` and `customizations/api/{Controllers,Models,Services,Helpers}` as class search paths — a custom class with the same name as a base one is found first if placed earlier in that list, but in practice customizations extend/replace by unique naming, not shadowing.

**All routes are registered in one place**: `base-pos/api/Router.php`, both core and custom. To find or add an endpoint, start there:

```php
$this->routes[] = ['route' => 'resource/action', 'controller' => 'FooController', 'method' => 'bar', 'verb' => 'GET'];
```

- Single-resource fetches use a `?id=` query param, e.g. `sale-lots/sale-lot?id=123`.
- Only `auth/login`, `auth/verify`, and `purchase-orders/photos` are public (no JWT) — `purchase-orders/photos` instead validates an HMAC token for QR-code photo upload handoff.

### Auth

`POST /auth/login` sets an httpOnly cookie `posToken`. `Router::checkAuth()` tries the cookie first, then falls back to `Authorization: Bearer` header. JWT payload carries `branch_id` for scoping.

### API conventions

Response envelope:
```json
{ "status": "success"|"error", "data": {...}, "message": "..." }
```

JS side (`assets/js/common.js`):
```javascript
const res = await apiRequest('sale-lots', 'POST', payload);
showNotification('บันทึกสำเร็จ', 'success');
```

Naming: routes `kebab-case/action`, DB tables `snake_case` plural, JS identifiers `camelCase`, PHP `PascalCase` classes / `camelCase` methods.

### Database migrations

- **Never modify** `base-pos/database/pos_system.sql` (base schema).
- All schema changes go in `customizations/database/migrations/NNN_description.sql`, guarded with `IF NOT EXISTS` (migrations may be re-run).
- Applied in numeric order by `run-migrations.sh`. There are duplicate-numbered files from history — when adding a new migration, check the highest existing number rather than assuming no gaps/collisions.

### Business logic: race safety on Sale Lots

FIFO costing on sale lot create/confirm follows a specific locking sequence — read `customizations/api/Models/SaleLot.php` before touching this:
1. `SELECT ... FOR UPDATE` locks the lot row (TOCTOU protection).
2. `deductStock()` runs *inside* the same transaction as `create()` — not after.
3. Stock deduction is an atomic conditional `UPDATE` on `purchase_order_items.consumed_qty`.
4. On cancel, `consumed_qty` is restored in LIFO order inside the transaction.

Branch scoping: non-admin users are restricted to their JWT `branch_id` in every controller — check this pattern when adding a new controller that reads/writes branch-scoped data (see `SaleLotsController::store()` for the reference implementation).

### Cost methods

Per-branch configurable: FIFO or weighted-average. This affects how `PurchaseOrder`/`SaleLot` models compute cost basis — check which method a branch uses before assuming FIFO-only behavior.

### Frontend

Vanilla JS ES6, no build step, no framework. Admin pages under `base-pos/admin/*.html` load `assets/js/<page>.js` directly. Mobile-only flows live under `base-pos/mobile/` as standalone HTML (currently just the PO wizard), reusing the same API rather than a separate backend.

Design system is documented in `DESIGN.md` (Google Stitch format): amber primary `#D97706`, slate sidebar `#1e293b`, IBM Plex Sans Thai font.

## Reference docs

| Task | Read first |
|------|-----------|
| UI/design changes | `DESIGN.md` |
| Route registration | `code/base-pos/api/Router.php` |
| API config/env | `code/base-pos/api/config.php` |
| Installation | `code/docs/INSTALLATION.md` |
| Technical/architecture detail | `code/docs/TECHNICAL.md` |
| User manual (Thai) | `code/docs/USER-GUIDE.md` |

## Latest work: WF-05 Purchase Flow UX (2026-07-13)

Client demo feedback → **Cashier-Flow-First** redesign:

| Change | File |
|:-------|:-----|
| **Layout** — ผู้ขาย → บิล → สาขา → +ผู้ขายใหม่ | `admin/purchase-orders.html` |
| **บิล1 default** — `globalTier.level = 1` | `assets/js/purchase-orders.js` |
| **Auto-select product** — 1 result → auto | `assets/js/purchase-orders.js` |
| **Select-all on focus** — no need to clear weight | `assets/js/purchase-orders.js` |
| **Focus ring** — `:focus` vs `:focus-visible` | `assets/css/components/forms.css` |
| **Bottom row** — 0.8fr / 1fr / 1.2fr | `assets/css/components/purchase-orders.css` |

Detailed spec: `code/docs/workflows/WORKFLOW-05-purchase-flow-ux.md`

## Cautions

- **DO NOT** restore dead retail POS (SalesController, sales.html) — decided 14 Jun 2026
- **DO NOT** add Penpot integration — on hold indefinitely
