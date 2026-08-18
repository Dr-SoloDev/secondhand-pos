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

### 3.1 ไม่สร้าง bank ledger เต็มรูปแบบในเฟสแรก

เพื่อจำกัดความเสี่ยง เฟสแรกจะรองรับเฉพาะ:

- `drawer`
- `business_reserve` (รวม safe/Owner)

payment method `bank_transfer` ยังคงอยู่ใน PO, Sale Lot และ expenses ตามเดิม แต่ไม่สร้าง movement ใน cash drawer และไม่ต้องมี `cash_session.status = open`

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
total_cash      = drawer_balance + reserve_balance
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

## 4. Session lifecycle

### 4.1 Opening

ข้อมูลจาก cashier/ผู้เปิด:

- `drawer_opening_actual`: เงินที่นำมาไว้ในลิ้นชักจริง
- optional note/source

Algorithm:

1. อ่าน `reserve_balance` และ `business_total_cash` ล่าสุดของสาขา
2. จำนวนที่นำเข้าลิ้นชักจาก reserve = `min(drawer_opening_actual, reserve_balance)`
3. ถ้าจำนวนเกิน reserve ให้ส่วนเกินเป็น `owner_capital_injection`
4. สร้าง transfer `business_reserve -> drawer` ตามจำนวนที่มีอยู่จริง
5. สร้าง external increase เฉพาะส่วนเกิน
6. ไม่ถือว่าส่วนต่างระหว่างยอดรวมเดิมกับ drawer opening เป็น variance โดยอัตโนมัติ
7. เปิด drawer session เมื่อข้อมูลและ ledger transaction สำเร็จใน transaction เดียว

ตัวอย่าง: total=30,000, reserve=30,000, opening drawer=20,000 -> transfer 20,000, total ยัง 30,000

ตัวอย่าง: total=30,000, reserve=30,000, opening drawer=40,000 -> transfer 30,000 + capital 10,000, total ใหม่ 40,000

### 4.2 During day

- cash PO: ต้องมี open session และเงินใน drawer เพียงพอ
- cash expense: ต้องมี open session และเงินใน source location เพียงพอ
- cash Sale Lot revenue: ต้องมี open session แล้วเพิ่มเข้า drawer
- bank transfer PO/expense/revenue: ไม่ต้องมี open drawer session และไม่สร้าง drawer movement
- reserve -> drawer top-up: บันทึก internal transfer ไม่เพิ่ม total
- external owner top-up: บันทึก increase เฉพาะเงินจริงที่เพิ่มจากภายนอก

### 4.3 Closing

ร้านนำเงินออกจากลิ้นชักทุกครั้งเมื่อปิดวัน ดังนั้น closing flow ใช้กติกานี้:

1. นับ `drawer_closing_actual`
2. เปรียบเทียบกับ `drawer_balance` ตาม ledger
3. หากต่างกัน ให้บันทึก variance/reason และใช้ approval ตาม policy
4. เมื่อปิดสำเร็จ ให้สร้าง transfer `drawer -> business_reserve` จำนวน `drawer_closing_actual` อัตโนมัติ
5. หลังปิดสำเร็จ `drawer_balance = 0` และ `business_total_cash` ไม่เปลี่ยนจากการ transfer
6. หากปิดไม่ผ่าน/ถูก reject ห้ามสร้าง transfer และ session ยังคงเปิดเพื่อ recount

การนำเงินเข้าระบบปิดวันจึงไม่ใช่การลดเงินรวม แต่เป็นการย้ายเงินไป reserve

### 4.4 Reopen

การเปิดรอบที่ปิดแล้วใหม่ต้อง:

- เป็น admin-only ตาม policy เดิม
- ต้องระบุเหตุผล
- ย้อนสถานะ drawer session อย่าง audit ได้
- ห้ามสร้างยอดซ้ำจากการ re-open
- หากมีการ transfer drawer -> reserve ไปแล้ว ต้องสร้าง reversal transfer ที่จับคู่กับรายการเดิม ไม่ใช้ rebase แบบไม่มี location

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

### Core scenarios

- Day 1: open 0 -> capital 50,000 -> purchase 20,000 -> close transfer
- Day 2: total 30,000 -> open drawer 20,000 -> total remains 30,000
- Day 2: total 30,000 -> open drawer 40,000 -> capital increase 10,000
- reserve top-up during day does not increase total
- cash purchase reduces drawer and total
- bank purchase succeeds while drawer session is closed
- cash sale increases drawer and total
- bank sale does not alter drawer
- owner expense records reason and reduces total
- close transfers all counted drawer cash to reserve
- variance reject keeps session open and creates no close transfer
- reopen reverses transfer exactly once

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
