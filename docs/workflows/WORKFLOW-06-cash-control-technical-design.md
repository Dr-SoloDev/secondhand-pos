# WF-06 Technical Design: Cash Position และ Daily Drawer Session

**สถานะ:** DESIGN DRAFT — ยังไม่อนุญาตให้แก้ production code
**เอกสาร business source:** `docs/workflows/WORKFLOW-06-cash-control-redesign.md`
**วันที่:** 18 สิงหาคม 2569
**Baseline production:** `81d2306`

เอกสารนี้แปลง business rules ของ WF-06 เป็น technical design สำหรับ implementation และ review ก่อนเขียนโค้ด

---

## 1. Design goals

1. แยกยอดเงินรวมของกิจการออกจากยอดเงินในลิ้นชัก
2. รองรับการย้ายเงินระหว่างลิ้นชักกับ reserve โดยไม่เปลี่ยนยอดรวม
3. ไม่บังคับ cash session กับธุรกรรมที่จ่าย/รับผ่านธนาคาร
4. รักษา historical cash sessions และ API เดิมให้อ่านได้
5. เพิ่มความสามารถแบบ additive ไม่เปลี่ยน schema เก่าด้วยการตีความใหม่
6. ทำให้ยอดทุกขั้นตรวจสอบย้อนกลับได้จาก immutable ledger
7. จำกัด scope ไม่ให้กระทบ FIFO, stock transfer, seller, receipt และ printer

---

## 2. Terminology

| คำ | ความหมาย |
|---|---|
| `business_total_cash` | เงินสดรวมของกิจการใน drawer + reserve |
| `business_total_funds` | เงินทั้งหมดของกิจการ รวม cash และ bank เมื่อมี bank ledger |
| `drawer_balance` | เงินสดที่อยู่ในลิ้นชักและพร้อมใช้หน้าร้าน |
| `reserve_balance` | เงินสดของกิจการที่อยู่ในเซฟหรือกับ Owner รวมเป็นบัญชีเดียวในเฟสแรก |
| `bank_balance` | เงินผ่านบัญชีธนาคาร; ยังไม่ทำ bank ledger เต็มรูปแบบในงานนี้ |
| internal transfer | การย้ายตำแหน่งเงิน ไม่เพิ่ม/ลด `business_total_cash` |
| external cash movement | เงินทุนใหม่, รายรับ, รายจ่าย หรือการเบิกที่เปลี่ยน `business_total_cash` |
| drawer session | รอบการรับผิดชอบลิ้นชักของสาขาในหนึ่ง business date |

Invariant หลัก:

```text
business_total_cash = drawer_balance + reserve_balance
business_total_funds = business_total_cash + bank_balance
```

เฟสนี้รับรองเฉพาะ `business_total_cash` และไม่อ้างว่ายอดนี้รวมเงินธนาคารแล้ว จนกว่าจะมี bank ledger อย่างเป็นทางการ ธุรกรรม bank transfer ต้องไม่ถูกบล็อกด้วย drawer session และยังคงถูกแยกตาม payment method ในรายงานเดิม

---

## 3. Recommended data model

### 3.1 ไม่สร้าง bank ledger เต็มรูปแบบในเฟสแรก (v2.1: bank กระทบ total ผ่าน bank_net)

เพื่อจำกัดความเสี่ยง เฟสแรกยังไม่ทำ bank ledger เต็มรูปแบบ แต่ **bank จ่าย/รับต้องตัดยอดรวมให้ถูก** (D3):

- payment method `bank_transfer` ใน PO, Sale Lot และ expenses → สร้าง movement ประเภท `bank_*` (ผ่าน `CashSession::recordBankMovement()`) — **ไม่แตะ drawer/reserve**
- `bank_net = sum(bank_increase) - sum(bank_decrease)` — `business_total_cash = drawer + reserve + bank_net`
- bank movement ผูก `cash_session_id` (schema FK NOT NULL) — ผูกกับ session `status IN ('open','pending_close')` ล่าสุดของสาขา (bank ยังทำงานได้ขณะรออนุมัติปิด)
- bank movement ต้องไม่ถูกนับใน drawer (movementTotal กรอง `movement_type NOT LIKE 'bank\_%'`)

### 3.2 Additive columns ใน `cash_movements`

คง columns เดิมไว้เพื่อ backward compatibility และเพิ่ม metadata สำหรับตำแหน่งเงิน:

```text
source_location       ENUM('drawer','business_reserve','external') NULL
destination_location  ENUM('drawer','business_reserve','external') NULL
balance_effect        ENUM('transfer','increase','decrease')
business_date         DATE NULL
```

ความหมาย:

| ประเภท | source | destination | effect |
|---|---|---|---|
| Reserve -> drawer | reserve | drawer | transfer |
| Drawer -> reserve | drawer | reserve | transfer |
| Owner เติมทุนใหม่ | external | reserve/drawer | increase |
| ซื้อของเงินสด | drawer | external | decrease |
| รับเงินขาย Lot | external | drawer | increase |
| Owner/ค่าใช้จ่ายเงินสด | drawer หรือ reserve | external | decrease |

รายการ legacy ที่ไม่มี location metadata ต้องอ่านได้เหมือนเดิมและแสดงเป็น `legacy_unlocated` ห้ามแก้ย้อนหลังโดยอัตโนมัติ

### 3.3 Cash account balance

ไม่เก็บยอด balance ที่แก้ไขได้แยกเป็นตัวเลขหลักในเฟสแรก ให้คำนวณจาก ledger เพื่อลดความเสี่ยงยอด drift:

```text
drawer_balance  = opening baseline + sum(entries affecting drawer)
reserve_balance  = opening baseline + sum(entries affecting reserve)
bank_net         = sum(bank increases) - sum(bank decreases)
total_cash      = drawer_balance + reserve_balance + bank_net
```

หาก performance ไม่พอภายหลัง ค่อยเพิ่ม snapshot table พร้อม reconciliation job เป็น phase แยก

### 3.4 Cutover baseline ต่อสาขา

เพิ่มตาราง `cash_position_baselines` เพื่อเก็บยอดเริ่มต้นที่ Owner ยืนยัน ณ วันที่เปิดใช้โมเดลใหม่:

```text
branch_id          UNIQUE
effective_date     DATE
drawer_balance     DECIMAL(14,2)
reserve_balance    DECIMAL(14,2)
note               VARCHAR(500)
created_by         INT
created_at         DATETIME
```

- ถ้าสาขายังไม่มี baseline ให้ทำงานด้วย legacy model ต่อไป
- migration ห้ามเดายอด baseline จาก historical rows
- Admin ต้องยืนยัน drawer/reserve ของแต่ละสาขาก่อนเปิดใช้ v2
- หลังสร้าง baseline แล้วห้ามแก้ตัวเลขโดยตรง การแก้ต้องผ่าน adjustment ledger

---

## 4. Session lifecycle (v2.1 — ตาม Owner decisions 18 ส.ค. 2026)

> **v2.1 เปลี่ยนจาก design เดิม (Codex):** เปิด = rollover (ไม่ transfer/capital อัตโนมัติ), ปิด = ไม่ย้ายเงิน, reopen = ไม่มี reversal, bank = บันทึก bank movement กระทบ total ไม่แตะ drawer

### 4.1 Opening (rollover)

ข้อมูลจาก cashier/ผู้เปิด: **ไม่มี input ยอด** — ระบบแสดงยอด rollover ให้ยืนยัน

Algorithm:

1. อ่าน `drawer_balance` ล่าสุดของสาขา (จาก ledger — ตอน open ยังไม่มี session เปิด)
2. `opening_actual = drawer_balance` (ยอดลิ้นชักจาก ledger — เงินจริงค้างในลิ้นชักข้ามคืนตาม D1)
3. `reserveTransfer = 0`, `capitalInjection = 0` — **ไม่มีการ transfer/capital อัตโนมัติ**
4. เปิด drawer session `status=open` — ledger ไม่เปลี่ยน (นอกจาก session row)

เงินเข้าลิ้นชักเพิ่มระหว่างวัน = ผ่าน deposit flow (`reserve_transfer` / `owner_capital`) เท่านั้น

ตัวอย่าง: ปิดเมื่อวาน ledger drawer=10,000 → เปิดวันนี้ = opening 10,000, total คงเดิม

### 4.2 During day

- cash PO: ต้องมี open session และเงินใน drawer เพียงพอ (`assertDrawerSufficient`) — ไม่พอ → error "เงินสดในลิ้นชักไม่เพียงพอ กรุณาเติมเงินเข้าลิ้นชักก่อน"
- cash expense: ต้องมี open session และเงินใน drawer เพียงพอ
- cash Sale Lot revenue: ต้องมี open session แล้วเพิ่มเข้า drawer
- **bank transfer PO/expense/revenue: ไม่แตะ drawer — สร้าง `bank_*` movement (บันทึกผ่าน `recordBankMovement`) ผูก session open/pending_close ล่าสุด — กระทบ `business_total_cash` ผ่าน `bank_net`** (การไม่มี session เปิด = bank ทำงานไม่ได้ ตาม schema FK — ตรวจสอบ/บันทึกให้ชัดเจน)
- reserve -> drawer top-up: deposit `reserve_transfer` — internal transfer ไม่เพิ่ม total
- drawer -> reserve: deposit `drawer_to_reserve` — internal transfer ไม่ลด total
- external owner top-up: deposit `owner_capital` — increase เฉพาะเงินจริงที่เพิ่มจากภายนอก
- owner เบิกจากลิ้นชัก: deposit `drawer_to_owner` — decrease (เงินออกจากระบบ) — ต้องไม่เกินยอด drawer

### 4.3 Closing (ไม่ย้ายเงิน)

ตาม D1 — เงินจริงผู้บริหารจัดการเอง ระบบแค่บันทึก:

1. นับ `drawer_closing_actual` (กรอก — expected = `drawer_balance` ตาม ledger)
2. เปรียบเทียบกับ `drawer_balance` — ต่างกัน → variance/reason + approval ตาม policy
3. เมื่อปิดสำเร็จ → `status=closed` — **ไม่มีการสร้าง transfer** (`closing_transfer_amount = 0` — ไม่มี movement ใดๆ เพิ่ม)
4. `drawer_balance` ยังคงเท่าเดิม (ค้างในระบบ) — `business_total_cash` ไม่เปลี่ยน
5. หากปิดไม่ผ่าน/ถูก reject → session ยังคงเปิดเพื่อ recount

### 4.4 Reopen

การเปิดรอบที่ปิดแล้วใหม่ต้อง:

- เป็น admin-only ตาม policy เดิม
- ต้องระบุเหตุผล
- **ไม่มี reversal movement** (ไม่เคยมี transfer ตอนปิด) — แค่ย้อนสถานะ `closed -> open` อย่าง audit ได้
- ห้ามสร้างยอดซ้ำจากการ re-open

---

## 5. API compatibility

### Existing endpoints to preserve

- `GET cash-sessions/current`
- `POST cash-sessions/open`
- `POST cash-sessions/close`
- approval/reject/reopen endpoints
- deposit endpointsในช่วง transition

### New/changed response fields

เพิ่ม field โดยไม่ลบ field เดิม:

```json
{
  "business_total_cash": 30000,
  "drawer_balance": 20000,
  "reserve_balance": 10000,
  "drawer_opening_actual": 20000,
  "drawer_closing_actual": null,
  "pending_transfer_amount": 0
}
```

`current_expected_cash` เดิมต้องคงไว้ชั่วคราว แต่ UI ใหม่ห้ามใช้เป็น label ว่าเป็นเงินในลิ้นชักโดยไม่มีบริบท

### Deposit endpoint transition

ห้ามตีความคำขอเดิมทุกตัวเป็นเงินทุนใหม่โดยอัตโนมัติ ต้องเพิ่ม `source_type`:

- `reserve_transfer` — ย้ายเงินเดิมเข้า drawer
- `owner_capital` — เงินทุนใหม่

ถ้าไม่ส่งค่าในช่วง transition ให้ใช้ policy ที่ปลอดภัยและแจ้งผู้ใช้ชัดเจน ไม่เดาเงียบ ๆ

---

## 6. Database migration strategy

1. เพิ่ม columns แบบ nullable ใน `cash_movements`
2. เพิ่ม `cash_position_baselines` โดยไม่ seed ยอดอัตโนมัติ
3. เพิ่ม indexes สำหรับ `(branch_id, business_date, source_location, destination_location)` ตาม query จริง
4. เพิ่ม reference metadata สำหรับ transfer pair และ reversal pair หากจำเป็น
5. ไม่แก้ `base-pos/database/pos_system.sql`
6. ไม่แก้ migration เดิม 061-070
7. migration ใหม่ต้องมี `IF NOT EXISTS`/column guards ตาม convention
8. historical rows คงค่า NULL และถูกจัดกลุ่มเป็น legacy
9. ทำ cutover baseline จาก backup โดยมี opening reserve/drawer values ที่ Owner ยืนยัน

ห้าม migrate ยอดย้อนหลังด้วยการคาดเดาว่าเงินอยู่ใน drawer หรือ reserve

---

## 7. Code boundaries

### แก้ได้ในงานนี้

- `customizations/api/Models/CashSession.php`
- `customizations/api/Controllers/CashSessionsController.php`
- `customizations/api/Models/CashDepositRequest.php`
- จุดเรียก CashSession ใน `PurchaseOrder`, `BusinessExpense`, `SaleLot`
- `admin/cash-sessions.html`
- `assets/js/cash-sessions.js`
- migration ใหม่และ tests ใหม่

### ห้ามแก้

- FIFO/stock deduction
- stock transfer logic
- seller/ID encryption
- printer และ local thermal customization บน server
- production database rows โดยตรง

ทุกจุดที่แก้ต้องตรวจว่า transaction boundary เดิมยังครอบคลุม business write และ cash ledger write ใน transaction เดียว

---

## 8. Concurrency and integrity rules

- lock branch/session row ก่อนคำนวณ drawer balance แล้วบันทึก movement
- cash out ต้องตรวจ balance ภายใต้ lock เดียวกัน
- transfer ต้องบันทึกคู่ source/destination ใน transaction เดียว
- reference เดิมต้อง idempotent ไม่สร้าง movement ซ้ำ
- ห้ามยอด drawer ติดลบ
- ห้าม approval ซ้ำหรือ approve/reject state ที่เปลี่ยนไปแล้ว
- ledger write และธุรกรรมต้นทางต้อง commit/rollback พร้อมกัน

---

## 9. Test matrix

### Core scenarios (v2.1 — ตรง test_cash_position.sh 36 กรณี)

- Day 1: baseline 0 -> open 0 (rollover) -> capital 50,000 -> purchase cash 20,000 -> close **ไม่ย้าย** (drawer ค้าง 30,000)
- Day 2: open rollover = 30,000 (ไม่กรอก) -> total remains 30,000
- reserve top-up (`reserve_transfer`) during day does not increase total
- owner capital (`owner_capital`) during day increases total
- drawer -> reserve (`drawer_to_reserve`) does not change total, moves position
- drawer -> owner (`drawer_to_owner`) decreases total, blocked when drawer insufficient
- cash purchase reduces drawer and total; blocked when drawer insufficient (error message)
- bank purchase reduces total only (bank_net), drawer untouched
- bank sale increases total only, drawer untouched
- bank purchase while session pending_close works (bank ผูก session open/pending_close)
- close variance reject keeps session open and creates no transfer
- reopen makes no reversal movement
- bank movements excluded from drawer balance (movementTotal)

### Regression

- existing cash-session API tests
- PO idempotency and branch scope
- expense approval limits and self-approval policy
- Sale Lot confirmation/costing
- financial summary totals
- authorization matrix for cashier/manager/admin/super_manager

---

## 10. Rollout and rollback

### Before implementation

- Owner approves this design
- production remains paused
- take a fresh DB backup
- clone backup into isolated sandbox
- record baseline totals per branch

### Before production deploy

- all migration and tests pass
- cashier scenario test passes on sandbox
- Owner accepts displayed balances
- create a second production backup
- deploy code and migration together
- verify branch totals, drawer balance and reserve balance

### Rollback

- stop new cash operations
- preserve logs and failed deployment state
- do not run destructive down migration
- restore database only from verified backup if ledger integrity is compromised
- rollback application code to previous tag only when schema remains backward-compatible

---

## 11. Open decisions before coding

1. Confirm automatic full drawer -> reserve transfer at close
2. Confirm `business_reserve` combines safe and Owner cash in phase 1
3. Confirm bank ledger is phase 2, while bank transactions bypass drawer session now
4. Set cutover date and opening balances for each branch
5. Confirm UI wording for `reserve transfer` versus `owner capital`

No production implementation may start until these five decisions are accepted.
