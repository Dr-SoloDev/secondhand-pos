# Technical Reference

**Status:** aligned with code as of 2026-07-26

## Architecture

- Two-layer overlay: `base-pos/` core framework + `customizations/` for project-specific code.
- Route registration lives in `base-pos/api/Router.php`.
- Autoloader resolves base first, then custom classes shadow the base implementation.

## Auth

- Login sets `posToken` as an httpOnly cookie.
- API auth accepts cookie first, then `Authorization: Bearer`.
- Photo upload uses HMAC token handoff and does not require JWT.

## Stock Model

- `branch_stock` is the source of truth for per-branch per-item scrap inventory.
- `categories.stock_kg` is kept only for compatibility/backfill.
- Inventory item queries now read from `branch_stock`.
- Sale Lot confirm/cancel mutates `branch_stock` and `purchase_order_items.consumed_qty` in the same transaction.

Relevant files:
- `customizations/api/Models/BranchStock.php`
- `base-pos/api/Controllers/InventoryController.php`
- `customizations/api/Models/SaleLot.php`

## Sale Lots

- `POST /sale-lots` creates a `draft` lot only. Draft create does not mutate `branch_stock`, `categories.stock_kg`, or `purchase_order_items.consumed_qty`.
- `PUT /sale-lots/sale-lot?id=X` updates draft lots only and does not mutate stock.
- `POST /sale-lots/confirm?id=X` recomputes cost from current stock, changes the lot to `confirmed`, and deducts `branch_stock`, `categories.stock_kg`, and `purchase_order_items.consumed_qty` in one transaction.
- `POST /sale-lots/cancel?id=X` works only from `confirmed`, changes the lot to `cancelled`, and restores stock once.
- Confirm and cancel are branch-scoped for non-admin users.
- `POST /sale-lots/record-revenue?id=X` stores `actual_revenue`, note, and date.
- Cancel restores stock using the latest consumed purchase batches first.
- This flow now replaces the legacy retail sales screen for walk-in customers.

## Purchase Orders

- Precious-metal categories require seller ID card before save.
- Precious-metal / copper receipt flow requires signature on the frontend.
- `requires_precious_receipt` is driven by category flags.

## Reports

- Financial summary is based on `purchase_orders.total_amount`, `sale_lots.actual_revenue`, lot expenses JSON, and `business_expenses`.
- Dashboard and inventory reports now read branch-scoped data from the current branch context.

## Branch Scope

- Non-admin users are scoped to their own branch on inventory, sale lots, transfers, and reports.
- Admin can query across branches.

## Verification

```bash
cd code/tests/api
bash run.sh sale_lots_fifo
bash run.sh inventory
```
