# Secondhand POS — Code Project

**ระบบจัดการร้านรับซื้อของเก่า 4 สาขา | PHP 8.2 + MySQL 8.0 + Docker | Production Ready ✅**

---

## Quick Start

```bash
cp .env.example .env          # set JWT_SECRET + DB passwords
docker compose up -d           # start web (8080) + db (3307)
open http://localhost:8080/admin/   # admin / admin
```

---

## Structure

```
code/
├── base-pos/                       # Core POS framework (patched in-place)
│   ├── api/                        # Backend PHP
│   │   ├── Router.php              # ALL routes (core + custom) in ONE file
│   │   ├── index.php               # Entry point (catch → JSON)
│   │   ├── config.php              # DB + JWT from env
│   │   ├── autoload.php            # PSR-4 loader (base-pos + customizations)
│   │   ├── Core/                   # Database, Auth, Response, Model, Logger
│   │   ├── Models/                 # Built-in models
│   │   └── Controllers/            # Built-in controllers
│   ├── admin/                      # Admin UI (Vanilla JS)
│   └── assets/js/                  # config.js, common.js, animations.js
│
└── customizations/                 # Custom overlay — EDIT HERE
    ├── api/
    │   ├── Controllers/            # 11 controllers
    │   ├── Models/                 # 10 models
    │   └── Services/               # ReportService (14 methods)
        └── database/
            └── migrations/             # Active migrations (001-053 + patch variants)
```

---

## Containers

| Service | Image | Port |
|---------|-------|------|
| web | `php:8.2-apache` | 8080 → 80 |
| db | `mysql:8.0` | 3307 → 3306 |
| caddy | `caddy:2` | 443 → 443 (production) |

---

## Env Vars (`.env`)

| Variable | Default | Description |
|----------|---------|-------------|
| `JWT_SECRET` | — | JWT signing key |
| `MYSQL_ROOT_PASSWORD` | — | Root password |
| `MYSQL_USER` | `pos_user` | App DB user |
| `MYSQL_PASSWORD` | — | App DB password |
| `MYSQL_DATABASE` | `pos_system` | Database name |
| `APP_ENV` | `development` | `production` in prod |
| `DOMAIN` | — | Domain for Caddy |
| `ALLOWED_ORIGINS` | `*` | CORS |

---

## Migrations

Active migrations in `customizations/database/migrations/` — run via `bash customizations/database/run-migrations.sh root rootpass`. **DO NOT** modify `base-pos/database/pos_system.sql`.

---

## Testing

```bash
cd tests/api && bash run.sh
```

**CI** (`.github/workflows/test.yml`): PHP lint → migrations → PHP server → tests

---

## Key Design Points

- **Two-layer:** base-pos (core) + customizations (overlay), autoloader scans both
- **Route registration:** All routes in `base-pos/api/Router.php`
- **Cost methods:** FIFO (default) or Weighted Average per branch
- **Race safety:** `SELECT ... FOR UPDATE` + atomic conditional UPDATE on `consumed_qty`
- **Branch scope:** Non-admin enforced to own branch in inventory, sale lots, transfers, and reports
- **Stock source of truth:** `branch_stock` is the per-branch scrap inventory SSoT; `categories.stock_kg` is compatibility/backfill only
- **Idempotency:** Duplicate POST prevention via `idempotency_keys`

---

## API Pattern

```javascript
const res = await apiRequest('sale-lots', 'POST', payload);
// Response: { status: 'success'|'error', data: {...}, message: '...' }
```

Technical reference → `docs/TECHNICAL.md`

---

## Performance

| Metric | Result |
|--------|--------|
| GET avg | < 15ms |
| GET p95 | < 60ms |
| 5× concurrency | 10-21ms |
| Login (bcrypt) | 309ms |

---

*Built for SoloCorp OS by Dr.solodev*
