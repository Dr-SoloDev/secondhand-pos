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
# ⚠️ Currently non-functional — no composer.json/vendor/ in repo or container.
# All test files (FifoCalculationTest, NetProfitCalculationTest, PreciousReceiptEnforcementTest)
# are stub-only (markTestIncomplete). Set up composer before using these.
composer install    # if vendor/ missing
phpunit              # uses phpunit.xml (bootstrap: base-pos/api/autoload.php)
phpunit tests/Unit/FifoCalculationTest.php   # single file

# PHP syntax check — must run inside container (host has no php binary)
docker exec scrap-pos-web bash -c "find /var/www/html/base-pos/api -name '*.php' -exec php -l {} \;"
docker exec scrap-pos-web bash -c "find /var/www/html/customizations/api -name '*.php' -exec php -l {} \;"

# Deploy (production, from code/)
bash deploy.sh       # backup DB -> git pull -> rebuild containers -> migrate -> healthcheck -> rollback on failure
```

CI (`.github/workflows/test.yml`) does: PHP lint -> load `base-pos/database/pos_system.sql` -> run all migrations -> start `php -S` -> run `tests/api/run.sh`.

## Architecture

### Two-layer overlay

Two directories: `base-pos/` (core framework, patched in-place) and `customizations/` (overlay loaded via autoloader). Custom classes extend/replace by unique naming; `base-pos/api/autoload.php` registers both `base-pos/api/{Core,Controllers,Models,Services}` and `customizations/api/{Controllers,Models,Services,Helpers}` as class search paths — a custom class with the same name as a base one is found first if placed earlier in that list, but in practice customizations extend/replace by unique naming, not shadowing.

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
- **Docker entrypoint SQL** (`code/docker/entrypoint/`): mirrored copies of migrations applied automatically when the MySQL container first starts. Keep these in sync with `customizations/database/migrations/` when adding new migrations.

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

### Thai text encoding

`Response::generateJson()` (`base-pos/api/Core/Response.php`) passes all response data through `TextEncoding::normalize()` (`customizations/api/Helpers/TextEncoding.php`) which repairs UTF-8-interpreted-as-Latin1 mojibake before JSON encoding. All DB writes use `JSON_UNESCAPED_UNICODE` — never use `json_encode()` without it on Thai strings, or MySQL will strip backslashes from `\uXXXX` escapes if written via raw SQL rather than a bound parameter.

### Tier pricing (purchase orders)

`purchase_item_catalog.tier_prices` is a JSON column: array of `{label, price}`. Business convention: **บิล1 = lowest price, บิล3 = highest** (ascending). DB may store them in any order — the frontend (`selectCatalogItem` in `purchase-orders.js`) always sorts descending→ascending before use. Button labels are always rendered as position-based `บิล${i+1}` (not the stored `label` field) to avoid label/position drift.

## Reference docs

| Task | Read first |
|------|-----------|
| UI/design changes | `DESIGN.md` |
| Route registration | `code/base-pos/api/Router.php` |
| API config/env | `code/base-pos/api/config.php` |
| Installation | `code/docs/INSTALLATION.md` |
| Technical/architecture detail | `code/docs/TECHNICAL.md` |
| User manual (Thai) | `code/docs/USER-GUIDE.md` |

## Cautions

- **DO NOT** restore dead retail POS (SalesController, sales.html) — decided 14 Jun 2026
- **DO NOT** add Penpot integration — on hold indefinitely
