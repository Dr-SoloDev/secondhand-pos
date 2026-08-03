# Cash Sessions Feature — Quick Reference Guide

## Daily Workflow (ผู้ประกอบการรายวัน)

### Morning — Opening (เปิดยอด)

1. 📊 **Login** to Admin → "เปิด-ปิดยอด"
2. 🏪 **Select Branch** (dropdown)
3. 💵 **Count physical cash** in drawer
4. ➕ **Enter amount** in form
5. ❓ **Add reason** (if different from expected)
6. ✅ **Click "เปิดยอด"**

**Expected values shown:**
- **ยอดยกมาจากวันก่อน** = yesterday's closing cash
- If no match → **requires reason**
- If difference > ₿100 → **pending admin approval**

---

### During Day — Deposit Requests (เติมเงินสด)

**When you need more cash float:**

1. 💳 **Stay on Cash Sessions page**
2. **Fill deposit form:**
   - Amount (e.g., 5,000฿)
   - Source (e.g., "owner", "float replenishment")
   - Reason
3. ✅ **Click "ส่งคำขอเติมเงิน"**
4. ⏳ **Wait for admin approval**

**Once approved:**
- Cash automatically added to drawer
- Ledger updated instantly

---

### Evening — Closing (ปิดยอด)

1. 💵 **Count all cash** in drawer
2. 🔢 **System shows expected balance:**
   - Opening + all deposits − all expenses
3. 📝 **Enter counted amount**
4. ❓ **Reason** (if doesn't match)
5. ✅ **Click "ปิดยอด"**

**If variance > ₿100:**
- Status = "รออนุมัติปิดยอด"
- Admin must approve/reject

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
| 🔵 ปิดยอดแล้ว | Done | Reopen (admin only, same-day) |
| ⚫ ปฏิเสธ | Rejected | Reopen with new submission |

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
| Variance threshold | ₿100.00 | Triggers approval requirement |
| Minimum variance | ₿0.01 | Requires reason if exceeded |
| Max reason length | 500 chars | Text field limit |
| Session limit | 1 per branch/day | Only one active session |

---

## Common Scenarios

### Scenario 1: Opening with ₿50 shortage

```
Yesterday's close:  ฿10,000
Counted today:      ฿9,950
Variance:           -₿50 ✓ (< ₿100)

→ Status: OPEN (auto-approved)
→ No admin needed
```

### Scenario 2: Opening with ₿200 overage

```
Expected:  ฿10,000
Counted:   ฿10,200
Variance:  +฿200 ✗ (> ₿100)

→ Status: PENDING_OPEN
→ Admin must approve/reject
→ Reason required: "owner added float"
```

### Scenario 3: During day, cashier runs short

```
System shows: ฿8,500 available
Needs:        ฿10,000 for large customer
Requests:     ฿5,000 deposit

→ Request sent, awaits admin approval
→ Once approved: +฿5,000 in drawer
→ Now has ฿13,500
```

### Scenario 4: End of day, ฿300 missing

```
Expected: ฿13,200
Counted:  ฿12,900
Variance: -฿300 ✓ (< ₿100)

→ Status: CLOSED (auto-approved)
→ Note recorded: "counted as -฿300"
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
