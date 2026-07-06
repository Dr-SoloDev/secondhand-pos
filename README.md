# Secondhand POS — ระบบจัดการร้านรับซื้อของเก่า

**Junk Shop POS System** — Customized from [goragodwiriya/pos-system](https://github.com/goragodwiriya/pos-system) for 4-branch scrap buying business in Surin, Thailand.

> Owner: Dr.solodev | Last Updated: 2026-07-03 | Status: MVP Complete

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
├── base-pos/              # Core POS (goragodwiriya/pos-system) — patched in-place
│   ├── api/               # PHP Backend (Router, Controllers, Models, Core, Services)
│   ├── admin/             # Admin UI pages (active delivery target)
│   ├── pos/               # POS terminal
│   └── assets/            # CSS, JS (all admin JS in assets/js/)
│
└── customizations/        # Custom code loaded by autoloader
    ├── api/Models/        # Branch, Seller, PurchaseOrder, SaleLot, PurchaseItemCatalog, StockTransfer, BusinessExpense
    ├── api/Controllers/   # Branches, Sellers, PurchaseOrders, SaleLots, PriceTiers, Catalog, StockTransfers, PhotoUpload, Financial
    ├── database/          # 38 migrations + run-migrations.sh
```

### Stack
- **PHP 8.2** + Apache (Docker)
- **MySQL 8.0** (MariaDB-compatible)
- **Vanilla JS ES6** (no jQuery, no framework)
- **Docker Compose** (web + db + phpmyadmin)

---

## Features Implemented

### Core Business
- **Purchase Orders (รับซื้อ):** Multi-item PO with catalog autocomplete, seller search, weight deduction, price tiers, receipt printing
- **Sale Lots (ขาย Lot):** Full CRUD with draft/confirm/cancel, FIFO cost calculation, weighted average costing, profit tracking, actual revenue recording
- **Stock Transfers (โอนสต็อก):** Transfer stock between branches, pending/confirm/cancel with logistics tracking
- **Sellers (ผู้ขาย):** ID card tracking, blacklist with reason/timestamp, duplicate detection, branch association, photo upload
- **Inventory:** Category-based stock tracking with alert thresholds, price tiers
- **Catalog:** Master purchase catalog with auto-fill, price board, tier pricing (JSON)
- **Business Expenses:** Track expenses per branch/category with CRUD

### Cross-cutting
- Multi-branch support with data isolation
- Role-based access (admin/manager/cashier) with JWT branch_id scoping
- Responsive UI (desktop + tablet + mobile breakpoints)
- Reports: purchase report, sale lot report, financial summary, chart data, CSV export
- Dashboard: stat cards, sale lot chart, recent sale lots table
- Photo upload via QR code (HMAC token handoff)
- Financial dashboard: revenue, expenses, purchase totals, kg
- Login rate limiting (IP-based, persistent)
- JWT revocation via token blocklist

---

## Quick Start

```bash
cd code
docker compose up -d
```

| Service | URL | Credentials |
|---------|-----|-------------|
| Admin | http://localhost:8080/admin/ | admin / admin |
| API | http://localhost:8080/api/index.php/ | (JWT auth) |
| phpMyAdmin | http://localhost:8081/ | root / from .env |

### Setup
1. Copy `.env.example` → `.env` and set secrets
2. `docker compose up -d` starts all 3 containers
3. Database auto-initializes migrations on first start
4. Login at `/admin/` with default credentials

### Run Tests
```bash
cd tests/api
bash run.sh          # 9 test functions, 54 assertions — auth, branches, sellers, POs, sale lots, FIFO flow, catalog, price tiers, inventory
```

---

## Database

**38 migrations** in `customizations/database/migrations/`:

| Area | Migrations | Key Tables |
|------|-----------|------------|
| Branches | 001, 021 | `branches` (cost_method: fifo/weighted) |
| Sellers | 002, 011, 031 | `sellers` (id_card, phone, vehicle_plate, blacklist) |
| Purchase Orders | 004, 008, 010, 013, 020, 036 | `purchase_orders`, `purchase_order_items`, `purchase_order_photos` |
| Sale Lots | 009, 010, 017, 020, 029, 034, 035 | `sale_lots`, `sale_lot_items` |
| Catalog | 012, 015, 016, 026 | `purchase_item_catalog` (tier_prices JSON) |
| Price Tiers | 007 | `price_tiers` |
| Stock Transfers | 024, 025 | `stock_transfers` |
| Business Expenses | 030 | `business_expenses` |
| Categories | 022, 027, 028, 032, 033 | `categories` (alert_threshold, default_unit) |
| Seeds | 005, 006 | Default categories + demo data |
| Security | 037, 038 | `login_attempts`, `token_blocklist` |

---

## Project Status

### ✅ Completed (All Phases + P0-P2 Hardening)
- All 38 database migrations
- 8 custom Models + 10 custom Controllers + 4 base Services
- 9 test functions (54 assertions) — all passing
- FIFO costing with atomic consumed_qty + TOCTOU FOR UPDATE locking
- Weighted average cost (per-branch cost_method)
- Stock Transfers (pending → confirm/cancel with logistics)
- Photo upload (HMAC token + QR-based mobile upload)
- Business Expenses + Financial Summary dashboard
- Actual revenue tracking on sale lots
- Price board endpoint for all branches
- Stock alert thresholds per category
- Blacklist reason/timestamp tracking
- Precious metals receipt flag
- Token blocklist for JWT revocation
- Login attempt rate limiting (IP-based)
- Reports: purchase-report, sale-lot-report, sale-lot-chart, financial export, + more
- Dashboard charts + recent sale lots
- Responsive CSS (768px, 576px breakpoints)
- Security: JWT in .env, Docker secrets externalized, CORS from env
- Console cleanup: 9 console.log() removed
- Bug fixes: PO cancel, SL reference_no collision, PDO execute→query

### ⏳ Pending
- PHPUnit test framework integration
- HTTPS setup
- Auto-generate PO from low-stock alerts
- Tax/nightly batch reports
- Dedicated test files for StockTransfers, Financial, PhotoUpload

---

## Documentation Index

| File | Audience | Purpose |
|------|----------|---------|
| `AGENTS.md` | AI Agents | Full project context for Claude/Kimi |
| `DESIGN.md` | Designers/Devs | UI design system (Google Stitch) |
| `code/README.md` | Developers | Technical setup + code structure |
| `01-project-brief.md` | All | Original project scope |
| `05-research-report.md` | Developers | Base codebase analysis |
| `06-implementation-roadmap.md` | Developers | Step-by-step impl guide |
| `07-executive-summary.md` | Stakeholders | Decision document |

---

*Built for SoloCorp OS by Dr.solodev | Powered by Claude Code + Kimi CLI*
