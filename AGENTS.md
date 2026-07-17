# Secondhand POS — AI Agent Context

Junk shop POS (รับซื้อของเก่า) for 4 branches in Surin, Thailand.
Owner: Dr.solodev | Last verified: 2026-07-07

---

## Quick Start

```bash
cd code
cp .env.example .env          # set secrets
docker compose up -d           # start web (8080) + db (3307)
docker compose --profile caddy up -d   # start Caddy reverse proxy (production only)
docker compose down -v --remove-orphans && docker compose up -d --build  # full reset
```

| Service | URL | Login |
|---------|-----|-------|
| Admin | http://localhost:8080/admin/ | admin / admin |
| API | http://localhost:8080/api/index.php/ | (JWT via login) |

**Env vars required:** `JWT_SECRET`, `DB_PASS`, `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD`
**Env var for prod:** `APP_ENV=production`, `DOMAIN`, `ACME_EMAIL`
phpMyAdmin is **commented out in docker-compose.yml** (disabled in production).

---

## Architecture

**Two-layer overlay system** (`code/`):
- `base-pos/` — core POS framework ([goragodwiriya/pos-system](https://github.com/goragodwiriya/pos-system)), patched in-place
- `customizations/` — custom code loaded by autoloader at `base-pos/api/autoload.php`
- ALL routes registered in **single file**: `base-pos/api/Router.php` (core + custom)
- Custom controllers/models extend base `Controller`/`Model` classes

**Auth:** `POST /auth/login` sets httpOnly cookie `posToken`. Router tries cookie first, falls back to `Authorization: Bearer`. Photo upload endpoint (`purchase-orders/photos`) accepts HMAC token (no JWT needed).

---

## Key Conventions

### Route Registration
```php
$this->routes[] = ['route' => 'resource/action', 'controller' => 'FooController', 'method' => 'bar', 'verb' => 'GET'];
```
- Single resource pattern: `?id=` query param (e.g. `sale-lots/sale-lot?id=123`)
- Path ID extraction: queryId > pathId, numeric guard

### API Response
```json
{ "status": "success"|"error", "data": {...}, "message": "..." }
```

### JS API Helper
```javascript
const res = await apiRequest('sale-lots', 'POST', payload);  // Window.apiPath + endpoint
showNotification('บันทึกสำเร็จ', 'success');                // common.js
```

### Naming
- Routes: `kebab-case/action`, Tables: `snake_case` plural, JS IDs: `camelCase`, PHP: `PascalCase` classes / `camelCase` methods

### Migration Rules
- **DO NOT** modify `base-pos/database/pos_system.sql`
- All changes → `customizations/database/migrations/NNN_description.sql` (48 files, 001-048)
- Run via `run-migrations.sh` from `customizations/database/`; guard with `IF NOT EXISTS`
- Duplicate `006_*` files — skip one within a version number

---

## Testing

```bash
cd code/tests/api
bash run.sh                       # 84 tests, 12 test functions
bash run.sh auth sellers          # run specific groups (test_{group})
API_BASE=http://other:8080/api/index.php bash run.sh   # custom URL
```

**CI** (`.github/workflows/test.yml`): PHP lint → run migrations → start PHP server → run tests

---

## Business Logic: Race Safety

FIFO costing on sale lot **create/confirm**:
1. `SELECT ... FOR UPDATE` locks lot row (TOCTOU protection)
2. `deductStock()` runs **inside** `create()` transaction (atomic — fixed WF-02)
3. Atomic conditional UPDATE on `purchase_order_items.consumed_qty`
4. On **cancel**: restore consumed_qty using LIFO order inside transaction

### Branch Scope Enforcement
- All controllers enforce branch_id from JWT for non-admin users
- `SaleLotsController::store()` — branch scope check added (gap closed WF-01)

---

## Deploy

`code/deploy.sh` flow: backup DB → `git pull` → rebuild containers → run migrations → health check → (rollback on failure)
Healthcheck configured in Docker Compose for web container.

---

## Performance

| Metric | Result |
|--------|--------|
| GET endpoints avg | < 15ms |
| GET endpoints p95 | < 60ms |
| 5× concurrency | 10-21ms |
| Login (bcrypt) | 309ms avg |

---

## Design System

UI follows **Google Stitch format** documented in `DESIGN.md` (root):
- Amber primary `#D97706`, slate sidebar `#1e293b`
- Font: IBM Plex Sans Thai
- Warm shadows, high contrast (works in bright light)

---

## Purchase Flow UX (WF-05) — Client Requirements

> **Workflow doc:** `code/docs/workflows/WORKFLOW-05-purchase-flow-ux.md`
> **Last update:** 13 กรกฎาคม 2569 — Implemented + Approved ✅

**Layout order (Tab 순서):** `[ค้นหาผู้ขาย] → [บิล1/2/3] → [สาขา] → [+ผู้ขายใหม่]`

### Key Behaviours
| Behaviour | Description |
|:----------|:------------|
| **Default tier** | บิล1 auto-selected `globalTier.level = 1` |
| **Auto-select product** | If search returns 1 result → auto-select + focus next |
| **Select-all on focus** | `itemQuantity` / `itemWeightDeduct` → `this.select()` |
| **Focus ring** | `:focus` = box-shadow (mouse), `:focus-visible` = outline (keyboard Tab) |
| **Bottom row** | `0.8fr 1fr 1.2fr` (seller card / payment / save) |

### ⚠️ Cashier-Flow-First Principle
Design layout follows cashier's workflow order, not system logic. **Keyboard-first** — all fields must be reachable via Tab without mouse.

### Deploy Checklist
1. Copy `assets/js/purchase-orders.js` → server
2. Copy `admin/purchase-orders.html` → server
3. Copy `assets/css/components/purchase-orders.css` → server
4. Copy `assets/css/components/forms.css` → server
5. `docker compose restart web`

---

## Reference Files

| Task | Read First |
|------|------------|
| UI/design changes | `DESIGN.md` |
| Route registration | `base-pos/api/Router.php` |
| API config/env | `base-pos/api/config.php` |
| Installation guide | `docs/INSTALLATION.md` |
| User manual (Thai) | `docs/USER-GUIDE.md` |
| Technical docs | `docs/TECHNICAL.md` |
| Legacy learning | `.learnings/LEARNINGS.md` |
| OpenCode agents | `.opencode/agents/pos-architect.md`, `pos-designer.md` |
