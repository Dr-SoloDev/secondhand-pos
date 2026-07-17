# Secondhand POS — Agent Context

Junk shop POS (รับซื้อของเก่า) for 4 branches in Surin, Thailand.
**Owner:** Dr.solodev | **Tech:** PHP + MySQL + Docker

---

## Quick Start
```bash
cd code && docker compose up -d
# Admin: http://localhost:8080/admin/ (admin/admin123)
```

---

## Project Structure
- `code/base-pos/` — core POS framework (goragodwiriya/pos-system), patch in-place
- `code/customizations/` — custom code loaded via autoloader
- All routes: `base-pos/api/Router.php`

---

## 🚀 Latest: WF-05 Purchase Flow UX (2026-07-13)
Client demo feedback → **Cashier-Flow-First** redesign:

| Change | File |
|:-------|:-----|
| **Layout** — ผู้ขาย → บิล → สาขา → +ผู้ขายใหม่ | `admin/purchase-orders.html` |
| **บิล1 default** — `globalTier.level = 1` | `assets/js/purchase-orders.js` |
| **Auto-select product** — 1 result → auto | `assets/js/purchase-orders.js` |
| **Select-all on focus** — no need to clear weight | `assets/js/purchase-orders.js` |
| **Focus ring** — `:focus` vs `:focus-visible` | `assets/css/components/forms.css` |
| **Bottom row** — 0.8fr / 1fr / 1.2fr | `assets/css/components/purchase-orders.css` |

> ✅ **ครีเอท Approve** — พร้อม Deploy (13 ก.ค. 2569)

Detailed spec: `code/docs/workflows/WORKFLOW-05-purchase-flow-ux.md`

---

## Key Files
| Purpose | Path |
|:--------|:-----|
| Project memory (full) | `AGENT-MEMORY.md` |
| Agent instructions | `AGENTS.md` |
| Design system | `DESIGN.md` |
| Migrations | `code/customizations/database/migrations/` |
| Tests | `code/tests/api/` |
| UX spec | `code/docs/workflows/WORKFLOW-05-purchase-flow-ux.md` |

---

## ⚠️ Cautions
- **DO NOT** modify `base-pos/database/pos_system.sql` — use migrations only
- **DO NOT** restore dead retail POS (SalesController, sales.html) — decided 14 Jun 2026
- **DO NOT** add Penpot integration — on hold indefinitely
- **Env:** `JWT_SECRET`, `DB_PASS`, `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD` in `.env`
