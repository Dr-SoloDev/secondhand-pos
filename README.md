# Secondhand POS — ระบบจัดการร้านรับซื้อของเก่า

**Junk Shop POS System** — Customized from [goragodwiriya/pos-system](https://github.com/goragodwiriya/pos-system) for 4-branch scrap buying business in Surin, Thailand.

> Owner: Dr.solodev | Last Updated: 2026-06-01 | Status: MVP Complete

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
│   ├── api/               # PHP Backend (Router, Controllers, Models, Core)
│   ├── admin/             # Admin UI pages (active delivery target)
│   ├── pos/               # POS terminal
│   └── assets/            # CSS, JS (common.js, config.js)
│
└── customizations/        # Custom code loaded by autoloader
    ├── api/Models/        # Branch, Seller, PurchaseOrder, SaleLot, PurchaseItemCatalog
    ├── api/Controllers/   # Branches, Sellers, PurchaseOrders, SaleLots, PriceTiers, etc.
    ├── api/Services/      # ReportService (report engine)
    ├── database/          # 021 migrations + run-migrations.sh
    └── frontend-react/    # DEPRECATED (React v2, no longer active)
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
- **Sale Lots (ขาย Lot):** Full CRUD with draft/confirm/cancel, FIFO cost calculation, weighted average costing, profit tracking
- **Sellers (ผู้ขาย):** ID card tracking, blacklist, duplicate detection, branch association
- **Inventory:** Product management with SKU, categories linked to purchase catalog
- **Price Tiers:** Dynamic tier pricing per catalog item (stored as JSON, add/remove levels)

### Cross-cutting
- Multi-branch support with data isolation
- Role-based access (admin/manager/cashier) with JWT branch_id scoping
- Responsive UI (desktop + tablet + mobile breakpoints)
- Reports: purchase report, sale lot report, chart data, CSV export
- Dashboard: stat cards, sale lot chart, recent sale lots table

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
bash run.sh          # 47 tests — auth, branches, sellers, POs, sale lots, FIFO flow, catalog, price tiers
```

---

## Database

**21 migrations** in `customizations/database/migrations/001-021`:

| Area | Migrations | Key Tables |
|------|-----------|------------|
| Branches | 001, 021 | `branches` (cost_method: fifo/weighted) |
| Sellers | 002, 011 | `sellers` (id_card, phone, vehicle_plate) |
| Purchase Orders | 004, 008, 010, 013, 020 | `purchase_orders`, `purchase_order_items` |
| Sale Lots | 009, 010, 017, 020 | `sale_lots`, `sale_lot_items` |
| Catalog | 012, 015, 016 | `purchase_item_catalog` (tier_prices JSON) |
| Price Tiers | 007 | `price_tiers` |
| Seeds | 005, 006 | Categories + demo data |

---

## Project Status

### ✅ Completed (Phases 1-3 + P0 Hardening)
- All 21 database migrations
- 6 custom Models + 7 custom Controllers + 1 Service
- 47 automated tests (bash/curl) — all passing
- FIFO costing with atomic consumed_qty + TOCTOU FOR UPDATE locking
- Weighted average cost (per-branch setting, migration 021)
- 4 report endpoints + dashboard charts
- Responsive CSS (768px, 576px breakpoints)
- Security: JWT in .env, Docker secrets externalized, CORS from env
- Console cleanup: 9 console.log() removed
- Bug fixes: PO cancel, SL reference_no collision, PDO execute→query
- Tool Evaluator score: 7.9/10 (all P0 resolved)

### ⏳ Pending
- PHPUnit test framework integration
- HTTPS setup
- Rate limiting on auth
- Auto-generate PO from low-stock alerts
- Tax/nightly batch reports

---

## Documentation Index

| File | Audience | Purpose |
|------|----------|---------|
| `AGENTS.md` | AI Agents | Full project context for Claude/Kimi |
| `AGENT-MEMORY.md` | AI Agents | Session history + learnings |
| `COMPLETION-REPORT.md` | Stakeholders | Phase completion summary |
| `code/README.md` | Developers | Technical setup + code structure |
| `01-project-brief.md` | All | Original project scope |
| `05-research-report.md` | Developers | Base codebase analysis |
| `06-implementation-roadmap.md` | Developers | Step-by-step impl guide |
| `07-executive-summary.md` | Stakeholders | Decision document |

---

*Built for SoloCorp OS by Dr.solodev | Powered by Claude Code + Kimi CLI*
