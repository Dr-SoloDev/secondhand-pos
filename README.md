# Secondhand POS — ระบบจัดการร้านรับซื้อของเก่า

**Junk Shop POS System** — Customized from [goragodwiriya/pos-system](https://github.com/goragodwiriya/pos-system) for 4-branch scrap buying business in Surin, Thailand.

> Owner: Dr.solodev | Last Updated: 2026-07-12 | Status: **Production Ready** ✅ | **Mobile/Tablet Support** ✅ | **Architecture Score: 9/10**

---

## Quick Start

```bash
cd code
cp .env.example .env        # set JWT_SECRET, DB passwords
docker compose up -d         # start web (8080) + db (3307) + caddy
```

| Service | URL | Login |
|---------|-----|-------|
| Admin UI | http://localhost:8080/admin/ | admin / admin |
| API | http://localhost:8080/api/index.php/ | (JWT via login) |
| Caddy (HTTPS) | https://your-domain.com | (production) |

---

## Business Model

ร้านรับซื้อของเก่า ≠ ร้านค้าปลีกทั่วไป — สามธุรกิจในระบบเดียว:

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
├── base-pos/                  # Core POS framework — patched in-place
│   ├── api/                   # Router, Controllers, Models, Core, Services
│   ├── admin/                 # Admin UI pages (active delivery target)
│   ├── pos/                   # POS terminal
│   └── assets/                # CSS, JS (admin JS in assets/js/)
│
    ├── mobile/                   # Mobile-only pages
    │   └── purchase.html         #   Mobile PO wizard (4-step)
    │
    └── customizations/            # Custom overlay loaded by autoloader
        ├── api/Models/            # 10 custom models
        ├── api/Controllers/       # 11 custom controllers
        └── database/
            ├── migrations/        # 48 migrations (001-048)
            └── run-migrations.sh
```

### Stack
- **PHP 8.2** + Apache (Docker — 59MB RAM)
- **MySQL 8.0** (Docker — 409MB RAM)
- **Vanilla JS ES6** (no framework, no jQuery)
- **Docker Compose** (web + db + Caddy for HTTPS)

---

## Features

### Core Business
- **Purchase Orders (รับซื้อ):** Multi-item PO with catalog autocomplete, seller search, weight deduction, price tiers, precious metal receipt flag, receipt printing
- **Sale Lots (ขาย Lot):** Full CRUD with draft/confirm/cancel, FIFO costing (atomic `consumed_qty` + `FOR UPDATE`), weighted average cost, profit tracking, actual revenue recording, transport cost
- **Stock Transfers (โอนสต็อก):** Cross-branch stock transfer with pending→confirm/cancel workflow + logistics tracking (carrier, plate, driver)
- **Sellers (ผู้ขาย):** ID card (13-digit) validation, duplicate detection, blacklist with reason/timestamp/who, vehicle plate tracking, photo upload, PDPA consent, search index
- **Inventory:** Category-based stock tracking, alert thresholds, price tiers (3 tiers per category), unit management (kg/piece)
- **Catalog:** Master purchase catalog with auto-fill, price board (all branches), JSON tier pricing
- **Business Expenses:** Per-branch expense tracking with CRUD
- **Employees:** Employee records + salary/SSO expense generation

### Mobile & Tablet
- **Tablet-responsive** — touch-friendly 44px buttons, 16px font (iOS zoom prevention), column priority hiding, fullscreen modals on <640px, sidebar overlay + hamburger (769-1024px)
- **Mobile PO Wizard** — standalone `/mobile/purchase.html`: 4-step wizard (Branch → Seller → Items → Review & Save), catalog search with autocomplete, tier price bottom-sheet, reuses existing API

### Cross-cutting
- **Multi-branch** with data isolation, per-branch cost method (FIFO / weighted average)
- **Role-based access** — admin / manager / cashier with JWT branch_id scoping
- **Responsive UI** — desktop + tablet + mobile (768px, 576px breakpoints)
- **Dashboard:** Stat cards, sale lot chart, recent sale lots table
- **Reports:** Purchase report, sale lot report, financial summary, CSV export, chart data
- **Photo upload** via QR code + HMAC token handoff (no JWT needed)
- **Security:** JWT (httpOnly cookie + Bearer fallback), login rate limiting (IP-based), token blocklist (revocation), SQL injection (PDO prepared statements everywhere), race safety (atomic conditional UPDATE + FOR UPDATE), security headers (CSP, X-Frame-Options, X-Content-Type-Options)
- **Idempotency:** Duplicate POST prevention for POs and sale lots

---

## Competitive Advantage (vs 5 Thai Scrap POS Systems)

| Capability | POSPOS | Scrapee | Green2Get | ScaleBuy | **SoloCorp** |
|:-----------|:------:|:-------:|:---------:|:--------:|:------------:|
| Sale Lot + P&L per lot | ❌ | ❌ | ❌ | ❌ | ✅ |
| Multi-branch + Stock Transfer | ❌ | ❌ | ❌ | ❌ | ✅ |
| FIFO / Weighted Avg Costing | ❌ | ❌ | ❌ | ❌ | ✅ |
| Enterprise Security (CSP, JWT, audit) | ❌ | ❌ | ❌ | ❌ | ✅ |
| No monthly fee (self-hosted) | ❌ 990-2,990฿ | ❌ 2,990฿ | ❌ 290-2,990฿ | ❌ | ✅ **ฟรีตลอดชีพ** |
| Mobile/Tablet support | ✅ | ❌ | ✅ | ✅ | ✅ |
| Scale integration | ✅ | ✅ | ✅ | ❌ | ⏳ |
| Offline mode | ❌ | ❌ | ✅ | ❌ | ⏳ |

---

## Performance (Benchmarked 2026-07-07)

| Metric | Result |
|--------|--------|
| GET endpoints avg | **< 15ms** |
| GET endpoints p95 | **< 60ms** |
| 5× concurrency | **10-21ms avg** |
| Login (bcrypt + JWT) | **309ms avg** (expected) |
| Docker RAM usage | 468MB total (< 3% of available) |

---

## Testing

```bash
cd code/tests/api
bash run.sh                       # 84 tests, all passing
bash run.sh auth sellers          # run specific groups
API_BASE=http://other:8080/api/index.php bash run.sh
```

**CI** (`.github/workflows/test.yml`): PHP lint → run migrations → start PHP server → run tests

---

## Documentation

| File | Audience | Content |
|------|----------|---------|
| `AGENTS.md` | AI agents | Full project context |
| `DESIGN.md` | Designers/Devs | UI design system (Google Stitch) |
| `docs/INSTALLATION.md` | Ops | Docker, env, production deploy |
| `docs/USER-GUIDE.md` | Users | Step-by-step workflow manual (Thai) |
| `mobile/purchase.html` | Mobile users | Standalone mobile PO wizard (4-step) |
| `docs/TECHNICAL.md` | Developers | Architecture, API, DB, security |
| `docs/DEPLOY-CHECKLIST.md` | Ops | Pre-launch verification |

---

## License

Built for SoloCorp OS by Dr.solodev | Powered by Claude Code + Kimi CLI
