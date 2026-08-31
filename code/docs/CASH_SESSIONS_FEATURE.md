# Cash Sessions Feature — Simple Daily Drawer

**Status:** ✅ Implementation Complete (v2.0 — Simple Daily Drawer)  
**Last Updated:** 31 สิงหาคม 2569  
**Author:** SoloDev / Codebuff

---

## Overview

The **Cash Sessions** feature provides daily cash balance tracking and verification for each branch. It ensures accurate cash drawer management through:

- **Opening/Closing workflows** with variance approval (₿100 threshold)
- **Immutable audit trails** (cash_session_events)
- **Deposit requests** with approval workflow
- **Adjustment documents** for retroactive corrections (historical expenses, sale lot revenue fixes, PO cancellations)
- **Multi-branch support** with role-based access control

---

## Key Workflows

### 1. Daily Opening (เปิดยอด)

**Actor:** Cashier / Manager / Admin  
**Precondition:** None (can open anytime)  

```
┌─────────────────────────────────────────────────────────────────┐
│ Cashier enters the amount to put in the drawer                  │
└───────────────┬─────────────────────────────────────────────────┘
                │
                ├─→ opening_actual = entered amount
                ├─→ opening_expected = 0 (no carry-forward)
                ├─→ Status = OPEN
                │
                └─→ If session was closed/reopened same day:
                    └─→ Old movements are CLEARED
```

**Simple model:** Insert amount = drawer balance. No carry-forward from previous day.

**Database State:**
- `cash_sessions.opening_actual` = entered amount
- `cash_sessions.opening_expected` = 0
- `cash_sessions.opened_at` = NOW()
- `cash_session_events` record = "opened"

### 2. Daily Closing (ปิดยอด)

**Precondition:** Session status = OPEN  
**Expected Cash** = opening_actual + movement_total  
**No auto-transfer** — money stays in drawer

```
┌─────────────────────────────────────────────────────────────────┐
│ Cashier counts physical cash and submits closing balance        │
└───────────────┬─────────────────────────────────────────────────┘
                │
                ├─→ Expected = opening_actual + movement_total
                ├─→ Variance = actual − expected
                │
                ├─→ If variance = 0
                │   └─→ Status = CLOSED (immediate)
                │
                ├─→ If 0 < |variance| ≤ 100
                │   └─→ Status = CLOSED (reason required)
                │
                └─→ If |variance| > 100
                    └─→ Status = PENDING_CLOSE (awaits approval)
```

**Key difference from old model:** Close does NOT move money. No auto-transfer to safe.

**Cash Movement Tracking:**
- POs with `payment_method = "cash"` → OUT movement (deducts drawer)
- Deposit requests (approved) → IN movement (adds drawer)
- Sale lots with `payment_method = "cash"` → IN movement (adds drawer)
- Expenses with `payment_method = "cash"` → OUT movement (deducts drawer)
- Bank transfers → bank_net (does NOT touch drawer)

### 3. Variance Approval Flow (≥ ₿100)

**Admin/Super Manager approves/rejects pending opens/closes:**

```
PENDING_OPEN ──[approve]──> OPEN   (opened_by = approver, opened_at = NOW())
             ──[reject]──>  REJECTED (reopening requires new request)

PENDING_CLOSE ──[approve]──> CLOSED  (closed_by = approver, closed_at = NOW())
              ──[reject]──>  OPEN    (cashier recounts)
```

**Rules:**
- Cannot self-approve (opened_requested_by ≠ opened_by)
- Rejection reason required
- Audit trail recorded in cash_session_events

### 4. Reopen / Re-open Same Day

**Actor:** Any authorized user  
**Condition:** Session is closed or active

In the simple model, you can also **open again** directly (the system resets the session automatically):

```
OPEN (closed) ──[open again]──> OPEN (fresh, old movements cleared)
```

Or use the explicit reopen endpoint:
```
CLOSED ──[reopen]──> OPEN  (reason recorded)
```

**Key behavior:** When opening again same day, old movements are **deleted** and the new opening amount is used.

**Note:** Cross-day reopening creates a new session via `openDay()`.

### 5. Cash Deposit Requests (เติมเงินสด)

**Actor:** Cashier / Manager (within open session)

Controlled cash additions (e.g., teller floats, owner cash injection):

```
┌──────────────────────────────────────────────────┐
│ Cashier requests cash deposit (amount/source)   │
└──────────────────┬───────────────────────────────┘
                   │
                   ├─→ Status = PENDING (awaits approval)
                   │
                   ├─→ [Admin approves]
                   │   └─→ Records cash_movement (type="cash_deposit", direction="in")
                   │   └─→ Status = APPROVED
                   │   └─→ Updates current_expected_cash
                   │
                   └─→ [Admin rejects]
                       └─→ Status = REJECTED
```

**Database:**
- `cash_deposit_requests` (request/approval tracking)
- `cash_movements` (transactional record, immutable)

---

## Adjustment Documents (เอกสารปรับปรุง)

Admin-only retroactive corrections (admin-created, not cashier-requested).

### Types

#### 1. Purchase Order Cancellation (ยกเลิกใบรับซื้อย้อนหลัง)

Cancels a manual (not transfer-sourced) PO from **same day only**.

```
AdjustmentDocument (adjustment_type = "purchase_order_cancellation")
  ├─ effective_date = PO.created_at (must be today)
  ├─ target_type = "purchase_order", target_id = PO.id
  ├─ amount_before = PO.total_amount
  ├─ amount_after = 0
  ├─ payment_method_before/after = PO.payment_method
  └─ Triggers:
      ├─ PurchaseOrder.cancelInCurrentTransaction()
      └─ Cash movement reversal (if cash payment)
```

**Constraints:**
- Only manual POs (source_type = "manual")
- Cannot cancel if any items consumed (sale lots or stock transfers)
- Cross-day cancellation → must use separate workflow (document kept for audit)

#### 2. Sale Lot Revenue Correction (แก้รายรับ LOT)

Adjusts a confirmed sale lot's actual_revenue and payment method:

```
AdjustmentDocument (adjustment_type = "sale_lot_revenue_correction")
  ├─ target_type = "sale_lot"
  ├─ amount_before = old actual_revenue
  ├─ amount_after = new actual_revenue
  ├─ payment_method_before/after = cash | bank_transfer
  ├─ effective_date = date when payment received (≤ today)
  └─ Triggers:
      ├─ sale_lots.actual_revenue = new amount
      ├─ sale_lots.revenue_payment_method = new method
      ├─ sale_lots.actual_revenue_date = effective_date
      └─ Cash movement adjustment (if payment method differs)
```

**Constraint:** Only sale lots with status = "confirmed" and actual_revenue set.

#### 3. Historical Expense Addition (เพิ่มรายจ่ายย้อนหลัง)

Adds an expense that was missed in the past:

```
AdjustmentDocument (adjustment_type = "historical_expense")
  ├─ target_type = "business_expense"
  ├─ amount_before = 0
  ├─ amount_after = expense amount
  ├─ payment_method_after = cash | bank_transfer
  ├─ effective_date = when expense occurred (< today)
  └─ Creates:
      ├─ business_expenses record (auto-approved)
      ├─ adjustment_document.target_id = expense.id
      └─ Cash movement (if cash payment)
```

**Note:** Requires open session on effective_date to record cash movement.

---

## Database Schema

### Main Tables

#### `cash_sessions`
```sql
id                    INT PRIMARY KEY
branch_id             INT NOT NULL (FK: branches)
business_date         DATE NOT NULL
status                ENUM('pending_open','open','pending_close','closed','rejected')
opening_expected      DECIMAL(14,2)
opening_actual        DECIMAL(14,2)
opening_variance      DECIMAL GENERATED (actual - expected)
opening_reason        VARCHAR(500) -- required if variance exists
opening_requested_by  INT (FK: users)
opened_by             INT NULL (FK: users) -- null if pending
opened_at             DATETIME NULL
closing_expected      DECIMAL(14,2) NULL
closing_actual        DECIMAL(14,2) NULL
closing_variance      DECIMAL NULL GENERATED
closing_reason        VARCHAR(500)
closing_requested_by  INT NULL
closed_by             INT NULL
closed_at             DATETIME NULL
last_reviewed_by      INT NULL (FK: users) -- approver/reopener
last_reviewed_at      DATETIME NULL
last_review_note      VARCHAR(500)
created_at            DATETIME
updated_at            DATETIME

UNIQUE (branch_id, business_date)
```

#### `cash_movements`
```sql
id                INT PRIMARY KEY
cash_session_id   INT NOT NULL (FK: cash_sessions)
branch_id         INT NOT NULL (FK: branches)
direction         ENUM('in','out')
movement_type     VARCHAR(40) -- e.g., 'cash_deposit', 'sale_revenue', 'expense', etc.
amount            DECIMAL(14,2)
reference_type    VARCHAR(40) -- e.g., 'cash_deposit_request', 'sale_lot', 'business_expense'
reference_id      INT NULL
description       VARCHAR(500)
recorded_by       INT NOT NULL (FK: users)
created_at        DATETIME

UNIQUE (movement_type, reference_type, reference_id)
```

#### `cash_session_events`
```sql
id                INT PRIMARY KEY
cash_session_id   INT NOT NULL (FK: cash_sessions)
event_type        VARCHAR(40) -- 'open_requested','opened','open_approved','open_rejected',
                              -- 'close_requested','closed','close_approved','close_rejected','reopened'
expected_amount   DECIMAL(14,2) NULL
actual_amount     DECIMAL(14,2) NULL
variance_amount   DECIMAL(14,2) NULL
reason            VARCHAR(500)
actor_id          INT NOT NULL (FK: users)
reviewer_id       INT NULL (FK: users) -- for approvals
created_at        DATETIME
```

#### `cash_deposit_requests`
```sql
id              INT PRIMARY KEY
branch_id       INT NOT NULL (FK: branches)
cash_session_id INT NOT NULL (FK: cash_sessions)
amount          DECIMAL(14,2)
source_name     VARCHAR(200) -- e.g., "owner", "teller float"
reason          VARCHAR(500)
status          ENUM('pending','approved','rejected','cancelled')
requested_by    INT NOT NULL (FK: users)
requested_at    DATETIME
reviewed_by     INT NULL (FK: users)
reviewed_at     DATETIME NULL
review_note     VARCHAR(500) NULL

KEY (status, branch_id, requested_at)
```

#### `adjustment_documents`
```sql
id                    INT PRIMARY KEY
reference_no          VARCHAR(40) -- ADJ-YYYYMMDD-#####
branch_id             INT NOT NULL (FK: branches)
adjustment_type       ENUM('purchase_order_cancellation',
                           'sale_lot_revenue_correction',
                           'historical_expense')
effective_date        DATE NOT NULL
target_type           VARCHAR(40) -- e.g., 'purchase_order', 'sale_lot'
target_id             INT NULL
amount_before         DECIMAL(14,2) NULL
amount_after          DECIMAL(14,2) NULL
payment_method_before ENUM('cash','bank_transfer') NULL
payment_method_after  ENUM('cash','bank_transfer') NULL
reason                VARCHAR(500)
details_json          JSON NULL -- additional context
status                ENUM('posted') -- immutable
created_by            INT NOT NULL (FK: users)
created_at            DATETIME

UNIQUE (reference_no)
KEY (branch_id, effective_date)
KEY (target_type, target_id)
```

### Derived Fields

**cash_sessions.current_expected_cash (computed in model)**
```
= opening_actual + ledger_total
  (i.e., opening_actual + sum of all cash_movements)
```

---

## API Endpoints

### Cash Sessions

| Method | Endpoint | Auth | Params | Purpose |
|--------|----------|------|--------|---------|
| GET | `/cash-sessions` | cashier+ | branch_id, date_from, date_to | List all sessions (paginated) |
| GET | `/cash-sessions/current?branch_id=N` | cashier+ | branch_id | Get today's session (or blueprint if none) |
| POST | `/cash-sessions/open` | cashier+ | branch_id, actual_cash, reason | Open session |
| POST | `/cash-sessions/close` | cashier+ | branch_id, actual_cash, reason | Close session |
| POST | `/cash-sessions/open-approve` | admin+ | id, review_note | Admin approves pending open |
| POST | `/cash-sessions/open-reject` | admin+ | id, review_note | Admin rejects pending open |
| POST | `/cash-sessions/close-approve` | admin+ | id, review_note | Admin approves pending close |
| POST | `/cash-sessions/close-reject` | admin+ | id, review_note | Admin rejects pending close |
| POST | `/cash-sessions/reopen` | admin | id, reason | Reopen closed session (same day) |

### Cash Deposits

| Method | Endpoint | Auth | Params | Purpose |
|--------|----------|------|--------|---------|
| GET | `/cash-sessions/deposits?branch_id=N&status=pending` | cashier+ | branch_id, status | List deposit requests |
| POST | `/cash-sessions/deposit-request` | cashier+ | branch_id, amount, source_name, reason | Request cash deposit |
| POST | `/cash-sessions/deposit-approve` | admin+ | id, review_note | Admin approves deposit |
| POST | `/cash-sessions/deposit-reject` | admin+ | id, review_note | Admin rejects deposit |

### Adjustment Documents

| Method | Endpoint | Auth | Params | Purpose |
|--------|----------|------|--------|---------|
| GET | `/adjustment-documents?branch_id=N&adjustment_type=X&date_from=Y&date_to=Z` | admin | branch_id, filters | List all adjustments |
| POST | `/adjustment-documents` | admin | adjustment_type, ... (varies) | Create adjustment document |

**POST adjustment-documents payload variants:**

**PO Cancellation:**
```json
{
  "adjustment_type": "purchase_order_cancellation",
  "branch_id": 1,
  "target_reference": "PO-BR01-20260804-001",  // or numeric ID
  "reason": "ยกเลิกเนื่องจากสินค้ารับมาผิด"
}
```

**Sale Lot Revenue Correction:**
```json
{
  "adjustment_type": "sale_lot_revenue_correction",
  "branch_id": 1,
  "target_reference": "SL-20260804-001",
  "amount": 50000.00,
  "payment_method": "bank_transfer",
  "effective_date": "2026-08-04",
  "note": "โอนเข้าบัญชีจริงในวันนี้",
  "reason": "แก้ไขวันที่ได้เงินจริง"
}
```

**Historical Expense:**
```json
{
  "adjustment_type": "historical_expense",
  "branch_id": 1,
  "effective_date": "2026-08-02",
  "category": "ค่าแก๊สโซลีน",
  "amount": 3000.00,
  "payment_method": "cash",
  "beneficiary_name": "สถานีบริการเชื้อเพลิง",
  "note": "ตู้จ่ายเงิน",
  "reason": "พบใบเสร็จที่ลืมบันทึก"
}
```

---

## Frontend (UI/UX)

### Page: `/admin/cash-sessions.html`

**Sidebar:** Admin-only link (icon-money), positioned after "Reports"

**Main Layout:**

1. **Status Band** — Current session status + business_date
2. **Stats Grid** — Opening actual | Ledger total | System expected | Latest variance
3. **Session Action Panel** — Dynamic form (open/close count or approval UI)
4. **Deposit Requests Panel** — Request form + approval actions
5. **Movement Ledger Table** — All cash flows for today
6. **Deposit Requests Table** — All requests + approval/reject buttons
7. **Adjustment Documents Section** (admin-only)
   - Adjustment type selector (3 types)
   - Dynamic form fields per type
   - Adjustment history table

**Key JS Functions:**
- `loadCashPage()` — Fetch current session + deposits + adjustments
- `renderCashSession()` — Display session status + stats
- `renderSessionAction()` — Dynamic form (open/close/approval)
- `requestCashDeposit()` — POST deposit request
- `reviewDeposit()` — Approve/reject deposit
- `createAdjustmentDocument()` — POST adjustment

**CSS:** `assets/css/components/cash-sessions.css` (32 rules)

---

## Access Control & Permissions

### Role-Based Access

| Feature | Admin | Super Manager | Manager | Cashier |
|---------|-------|---------------|---------|---------|
| View today's session | ✅ All branches | ✅ All branches | ✅ Own | ✅ Own |
| Open/close session | ✅ All | ✅ All | ✅ Own | ✅ Own |
| Request cash deposit | ✅ All | ✅ All | ✅ Own | ✅ Own |
| Approve open/close (variance) | ✅ | ✅ | ❌ | ❌ |
| Approve cash deposits | ✅ | ✅ | ❌ | ❌ |
| Reopen closed session | ✅ | ❌ | ❌ | ❌ |
| Create adjustment documents | ✅ | ❌ | ❌ | ❌ |
| View adjustment history | ✅ | ❌ | ❌ | ❌ |

**Branch Scoping:**
- Non-admin users restricted to `branch_id` from JWT token
- Attempting cross-branch access → 403 error

---

## Business Logic Rules

### Variance Handling

```
|variance| ≤ ₿100.00  →  Auto-approved, status = OPEN/CLOSED
|variance| > ₿100.00  →  Requires admin approval, status = PENDING_*
variance = 0.00       →  No reason required
variance ≠ 0.00       →  Reason required
```

### Session Lifecycle Constraints

| Status | Allowed Actions | Cannot Do |
|--------|-----------------|-----------|
| None (new day) | open | close, deposit requests |
| PENDING_OPEN | [await approval] | nothing |
| OPEN | close, deposit requests, reopen? | open again |
| PENDING_CLOSE | [await approval] | close again |
| CLOSED | reopen (same day) | anything else |
| REJECTED | open (retry) | nothing else |

### Cash Movement Validation

- **Cashier attempts `out` movement** → Check balance: `opening_actual + sum_in − sum_out ≥ amount`
- **Reject if insufficient** → "เงินสดในลิ้นชักไม่เพียงพอ"

### Cross-Day Restrictions

- **Same-day only:**
  - PO cancellation (manual POs)
  - Deposit requests (within open session)
  
- **Any date (via adjustment):**
  - Historical expenses
  - Sale lot revenue corrections (up to today)

---

## Testing

### Unit Tests

None yet (PHPUnit setup pending).

### Integration Tests

**File:** `tests/api/test_cash_sessions.sh`

**Coverage:**
- Open session (no variance, small variance, large variance)
- Close session (same scenarios)
- Admin approval/rejection workflows
- Deposit request lifecycle
- Cash movement ledger accuracy
- Adjustment document creation (all 3 types)
- Permission enforcement

**Run:**
```bash
cd code/tests/api
bash run.sh cash_sessions
```

---

## Error Handling

### Common Errors

| Error | Cause | Recovery |
|-------|-------|----------|
| "สาขานี้มีรอบประจำวันที่ยังดำเนินการไม่เสร็จ" | Unfinished session exists | Close previous session first |
| "ยอดเงินจริงไม่ตรงยอดยกมา กรุณาระบุเหตุผล" | Variance > ₿0.01 with no reason | Add reason in form |
| "สาขานี้ยังไม่ได้เปิดยอดประจำวัน" | Close without open | Open session first |
| "เงินสดในลิ้นชักไม่เพียงพอ" | Deposit request > available balance | Request smaller amount |
| "ไม่สามารถขอยกเลิกใบรับซื้อที่ถูกนำไปใช้" | PO items consumed | Cannot cancel PO |
| "ใบรับซื้อข้ามวันต้องใช้เอกสารปรับปรุง" | Trying to cancel cross-day PO | Use adjustment document |

---

## Future Enhancements

- [ ] PDF receipt for session close (with variance explanation)
- [ ] SMS alert for pending approvals (variance > threshold)
- [ ] Cash reconciliation report (by movement type)
- [ ] Bulk deposit approval (multiple requests at once)
- [ ] Configurable variance approval threshold per branch
- [ ] Support for multi-currency float tracking (if needed)

---

## Related Features

- **Purchase Orders** — Payment method triggers cash movements
- **Sale Lots** — Revenue payment method + date tracking
- **Business Expenses** — Payment method + approval workflow
- **Stock Transfers** — PO source_type validation
- **Financial Reports** — Excludes pending sessions, includes only closed

---

## Migration Scripts

**Applying migrations (first time):**

```bash
cd code/customizations/database
bash run-migrations.sh [db_user] [db_password]

# Example with defaults (root / empty):
bash run-migrations.sh root ""
```

**Migrations included:**
- `060_add_purchase_order_cancellation_workflow.sql`
- `061_add_daily_cash_control.sql`
- `062_add_cash_deposit_requests.sql`
- `063_add_adjustment_documents.sql`

---

## Support & Debugging

### Check Current Session State

```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/api/index.php/cash-sessions/current?branch_id=1 | jq .
```

### Check Movement Ledger

```bash
# In UI, open cash-sessions.html, view "สมุดรายการเงินสดวันนี้" table
```

### Check Pending Approvals

```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/api/index.php/cash-sessions?branch_id=1 | jq '.data.items[] | select(.status | contains("pending"))'
```

### View Adjustment History

```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/api/index.php/adjustment-documents?branch_id=1 | jq .
```

---

## Changelog

### v1.0 (2026-08-04)

**Features:**
- Daily opening/closing workflow with variance approval
- Controlled cash deposit requests
- Purchase order cancellation workflow
- Sale lot revenue correction
- Historical expense addition
- Immutable audit trail (cash_session_events)
- Role-based access control
- Admin UI (cash-sessions.html)
- Integration tests

**Known Limitations:**
- Adjustment documents immutable (no edit/delete)
- PO cancellation restricted to same-day manual POs
- No SMS alerts yet
- Single currency only
