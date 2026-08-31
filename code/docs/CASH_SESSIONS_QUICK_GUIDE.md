# Cash Sessions Feature — Quick Reference Guide

## Daily Workflow (Simple Daily Drawer)

### Morning — Opening (เปิดยอด)

1. 📊 **Login** to Admin → "เปิด-ปิดยอด"
2. 🏪 **Select Branch** (dropdown)
3. 💵 **Enter the amount** to put in the drawer
4. ✅ **Click "เปิดยอด"**

**Simple model:**
- ใส่เท่าไหร่ = ลิ้นชักมีเท่านั้น
- **ไม่ยกยอดวันก่อน** — เริ่มใหม่ทุกเช้า
- **เปิดได้ครั้งเดียวต่อวัน** — เงินไม่พอก็ใช้ "เติมเงินเข้าลิ้นชัก" ห้ามเปิดซ้ำ
- ต้องแก้รอบที่ปิดแล้ว → ใช้ปุ่ม "เปิดใหม่" ของผู้ดูแล (ไม่ลบรายการเงินเดิม)

---

### During Day — Cash Operations

**ซื้อของเงินสด / ค่าใช้จ่าย:** ระบบตัดจากลิ้นชักอัตโนมัติ

**เติมเงินสด (เมื่อลิ้นชักไม่พอ):**
1. 💳 Stay on Cash Sessions page
2. 📝 Fill deposit form (จำนวน + เหตุผล)
3. ✅ Click "ส่งคำขอเติมเงิน"
4. ⏳ รอ admin อนุมัติ

---

### Evening — Closing (ปิดยอด)

1. 💵 **Count all cash** in drawer
2. 🔢 **System shows expected balance:**
   - ยอดเปิด + เติมเงิน − ซื้อของ − ค่าใช้จ่าย
3. 📝 **Enter counted amount**
4. ❓ **Reason** (if doesn't match)
5. ✅ **Click "ปิดยอด"**

**If variance > 100:**
- Status = "รออนุมัติปิดยอด"
- Admin must approve/reject

**No auto-transfer:** เงินค้างในลิ้นชัก ไม่ย้ายเข้าเซฟ

---

### Admin Approvals (อนุมัติ)

**Where:** Same page, "รอบประจำวัน" section  
**When:** Status = "รออนุมัติเปิดยอด" or "รออนุมัติปิดยอด"

1. 👀 **Review variance** (shows ยอดตามระบบ vs ยอดจริง)
2. 💬 **Optional note**
3. ✅ **Click "อนุมัติ"** or ❌ **Click "ปฏิเสธ"**

**If rejected:**
- Cashier must recount and reopen
- Previous attempt marked as "ปฏิเสธ"

---

## Session States (สถานะ)

| สถานะ | ความหมาย | สามารถทำได้ |
|-------|---------|-----------|
| 🔴 (ยังไม่เปิด) | No session yet | Open |
| 🟡 รออนุมัติเปิดยอด | Waiting admin | Nothing (wait) |
| 🟢 เปิดทำการ | Running | Close, Deposits |
| 🟡 รออนุมัติปิดยอด | Close pending | Nothing (wait) |
| 🔵 ปิดยอดแล้ว | Done | Open again (auto-reset) |
| ⚫ ปฏิเสธ | Rejected | Open again |

---

## Cash Movements (รายการเงินสดวันนี้)

**Automatic entries in ledger:**

| ประเภท | ทิศทาง | ตัวอย่าง |
|--------|--------|---------|
| การรับซื้อ | OUT | -฿500 (customer cash payment) |
| เติมเงิน | IN | +฿5,000 (approved deposit) |
| ค่าใช้จ่าย | OUT | -฿300 (expense payment) |
| ขาย Lot | IN/OUT | ±฿50,000 (depending on method) |

**Formula:**
```
Current Cash = Opening + (IN movements) − (OUT movements)
```

---

## Adjustment Documents (เอกสารปรับปรุง) — Admin Only

**Use when:** Need to fix historical records

### Type 1: ยกเลิกใบรับซื้อย้อนหลัง

**When:** PO recorded incorrectly, same-day only

```
Select: ยกเลิกใบรับซื้อย้อนหลัง
Enter: PO Number (e.g., PO-BR01-20260804-001)
Reason: Why cancelling
→ Creates adjustment doc, restores stock, reverses cash movement
```

### Type 2: แก้รายรับ LOT

**When:** Sale lot amount or payment date wrong

```
Select: แก้รายรับ LOT
Enter: 
  - Lot ID (e.g., SL-20260804-001)
  - Correct amount
  - Payment method (cash / bank)
  - Date received
  - Note
Reason: Why correcting
→ Updates LOT record, adjusts cash if method changed
```

### Type 3: เพิ่มรายจ่ายย้อนหลัง

**When:** Expense forgotten, need to add retroactively

```
Select: เพิ่มรายจ่ายย้อนหลัง
Enter:
  - Category (fuel, rent, etc.)
  - Amount
  - Payment method
  - Beneficiary name
  - Date incurred (must be past)
Reason: Why late entry
→ Creates expense record, records in cash ledger
```

---

## Permission Matrix

**Who can do what?**

|  | Cashier | Manager | Admin | Super |
|--|---------|---------|-------|-------|
| Open/Close session | Own branch | Own/All | All | All |
| Request deposit | Yes | Yes | Yes | Yes |
| Approve deposit | ❌ | ❌ | ✅ | ✅ |
| Approve variance | ❌ | ❌ | ✅ | ✅ |
| Reopen session | ❌ | ❌ | ✅ | ❌ |
| Create adjustment | ❌ | ❌ | ✅ | ❌ |

---

## Troubleshooting

### "เงินสดในลิ้นชักไม่เพียงพอ"

**Problem:** Trying to deposit more than available cash  
**Solution:**
- Verify expected cash amount (shown at top)
- Request smaller deposit amount
- Check if there are pending approvals

### "สาขานี้มีรอบประจำวันที่ยังดำเนินการไม่เสร็จ"

**Problem:** Cannot open new session  
**Solution:**
- Complete (open/close) previous day's session first
- Check session status at top of page

### "ยอดเงินจริงไม่ตรงยอดตามระบบ กรุณาระบุเหตุผล"

**Problem:** Variance exists but no reason given  
**Solution:** Always enter reason when amounts don't match

### "ไม่สามารถขอยกเลิกใบรับซื้อที่ถูกนำไปใช้"

**Problem:** Cannot cancel PO (already used)  
**Solution:** Cannot cancel POs that generated sale lots. Document it instead.

---

## Key Numbers

| Threshold | Value | Impact |
|-----------|-------|--------|
| Variance approval threshold | ฿100.00 | > 100 → requires admin approval |
| Minimum variance for reason | ฿0.01 | ≠ 0 → requires reason |
| Max reason length | 500 chars | Text field limit |
| Session limit | 1 per branch/day | Only one active (but can reset) |

---

## Common Scenarios

### Scenario 1: เปิดวัน 50,000

```
ใส่ตอนเปิด: ฿50,000
ลิ้นชัก = ฿50,000

→ Status: OPEN
→ ไม่ต้องกรอกยอดยืนยัน
```

### Scenario 2: ซื้อของ + เติมเงิน

```
เปิดวัน: ฿50,000
ซื้อของ: −฿20,000 → ลิ้นชัก = ฿30,000
เติมเงิน: +฿10,000 → ลิ้นชัก = ฿40,000

→ ลิ้นชักตามระบบ = ฿40,000
```

### Scenario 3: ปิดยอด ตรง

```
ยอดตามระบบ: ฿40,000
นับจริง:     ฿40,000
Variance:     0

→ Status: CLOSED (ทันที)
```

### Scenario 4: ปิดยอด ขาด 300

```
ยอดตามระบบ: ฿40,000
นับจริง:     ฿39,700
Variance:     −฿300

→ ต้องใส่เหตุผล
→ Status: CLOSED (auto-approved, < 100)
```

### Scenario 5: ปิดยอด ขาด 1,500

```
ยอดตามระบบ: ฿40,000
นับจริง:     ฿38,500
Variance:     −฿1,500

→ Status: PENDING_CLOSE (ต้องรออนุมัติ)
→ Admin อนุมัติ/ปฏิเสธ
```

### Scenario 6: เปิดใหม่วันเดียวกัน

```
ปิดยอดเช้า → เปิดรอบบ่าย
ใส่ใหม่: ฿30,000
clear old movements + เริ่มใหม่

→ ลิ้นชัก = ฿30,000 (ไม่ยกจากเช้า)
```

### Scenario 5: Mistake — need to fix yesterday's sale lot amount

```
Yesterday's SL: ฿50,000 (bank transfer recorded)
Actually received: ฿48,500 in cash today

→ Admin uses adjustment:
  - Type: แก้รายรับ LOT
  - Amount: ฿48,500
  - Method: cash (was bank)
  - Result: LOT corrected, cash added to today's movements
```

---

## Integration with Other Features

### Purchase Orders
- **Payment method = "cash"** → Auto-records OUT movement

### Sale Lots
- **Payment method = "cash"** → Auto-records IN movement
- Cross-day corrections → Use adjustment document

### Expenses
- **Payment method = "cash"** → Auto-records OUT movement
- Late entries → Use adjustment document

### Stock Transfers
- **No direct cash movement** (inter-branch only)
- Source POs cannot be cancelled

---

## Support

**Questions?** Check:
1. `CASH_SESSIONS_FEATURE.md` (detailed technical reference)
2. This page (quick guide)
3. Integration tests in `tests/api/test_cash_sessions.sh`

**Report bugs:** Include:
- Branch ID
- Session date
- Variance amount
- What happened vs. expected
