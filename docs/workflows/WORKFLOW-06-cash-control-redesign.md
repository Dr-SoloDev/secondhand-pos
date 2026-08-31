# WF-06: Cash Control — ระบบเปิด-ปิดยอด (Simple Daily Drawer)

**สถานะ:** ✅ IMPLEMENTED — Simple Daily Drawer Model
**วันที่แก้ไขล่าสุด:** 31 สิงหาคม 2569
**ผู้มีอำนาจยืนยัน business rules:** Owner / SoloCorp

เอกสารนี้เป็น source of truth สำหรับระบบเปิด-ปิดยอด ต้องอ่านเอกสารนี้ก่อนแก้โค้ดทุกครั้ง

---

## 1. สรุปโมเดล Simple Daily Drawer

ระบบเปิด-ปิดยอดแบบง่าย: **ใส่เท่าไหร่ = ลิ้นชักมีเท่านั้น** ไม่ยกยอดวันก่อน ไม่เก็บข้อมูลเงินที่ปิดยอดไปแล้ว

---

## 2. Business model — Simple Daily Drawer

### 2.1 หลักการหลัก

```
เปิดวัน = ใส่เท่าไหร่ = ลิ้นชักมีเท่านั้น (ไม่ยกยอดวันก่อน)
ระหว่างวัน = ซื้อของ = ตัดลิ้นชัก / เติมเงิน = เพิ่มลิ้นชัก
ปิดวัน = นับจริง vs ยอดตามระบบ = variance (ไม่ย้ายเงิน)
เช้าวันใหม่ = เริ่มใหม่ ไม่เก็บยอดปิด
```

### 2.2 สูตรคำนวณ

```
expected_cash = opening_actual + movement_total
variance = actual_counted - expected_cash
```

- `opening_actual` = ยอดเงินที่ใส่ตอนเปิดยอด
- `movement_total` = เติมเงิน(+) − ซื้อของ(−) − ค่าใช้จ่าย(−) + รับเงินขาย(+)
- `drawer_balance` = `expected_cash` (ยอดเงินในลิ้นชักตามระบบ)

### 2.3 การเปิดวัน

- ใส่เท่าไหร่ = ลิ้นชักมีเท่านั้น **ไม่ยกยอดวันก่อน**
- ถ้าเปิดรอบใหม่ในวันเดียวกัน (ปิดแล้วเปิดใหม่) → ระบบจะ **ล้าง movement เก่า** แล้วเริ่มใหม่จากยอดที่ใส่
- ไม่ต้องกรอกยอดยืนยัน ระบบแค่บันทึก

### 2.4 ระหว่างวัน

- **ซื้อของเงินสด**: ตัดจากลิ้นชัก (movement type = `purchase_payment`)
- **ค่าใช้จ่ายเงินสด**: ตัดจากลิ้นชัก (movement type = `business_expense`)
- **เติมเงิน**: เพิ่มลิ้นชัก (deposit request → approve → movement)
- **รับเงินขาย Lot เงินสด**: เพิ่มลิ้นชัก (movement type = `sale_lot_revenue`)
- **เงินโอนธนาคาร**: ไม่แตะลิ้นชัก (กระทบ bank_net เท่านั้น)

### 2.5 การปิดวัน

- นับเงินจริง vs ยอดตามระบบ = variance
- **ไม่ย้ายเงิน** — เงินค้างในลิ้นชัก (เงินจริงผู้บริหารจัดการเอง)
- variance ≤ 100 บาท → ปิดทันที (ต้องระบุเหตุผลถ้ายอดไม่ตรง)
- variance > 100 บาท → รออนุมัติจากผู้จัดการ/admin

### 2.6 เช้าวันใหม่

- เปิดใหม่ = ใส่ยอดใหม่ **ไม่เก็บ/ไม่ยก** ยอดปิดเก่า
- ไม่ต้องดึงจากเซฟ ไม่ต้องคำนวณ capital injection

### 2.7 ลิ้นชักต้องมีเงินเพียงพอ

PO/ค่าใช้จ่ายเงินสด ตรวจยอดลิ้นชักก่อนหัก — ถ้าไม่พอ → error "เงินสดในลิ้นชักไม่เพียงพอ กรุณาเติมเงินเข้าลิ้นชักก่อน"

---

## 3. ตัวอย่าง flow ที่ถูกต้อง

### วันแรก

| เหตุการณ์ | เงินในลิ้นชัก | หมายเหตุ |
|---|---:|---|
| เปิดวันใส่ 50,000 | 50,000 | ใส่เท่าไหร่ = ลิ้นชักเท่านั้น |
| ซื้อของเงินสด 20,000 | 30,000 | ตัดจากลิ้นชัก |
| เติมเงิน 10,000 | 40,000 | เพิ่มลิ้นชัก |
| ปิดยอด (นับได้ 39,500) | — | variance = −500, ต้องใส่เหตุผล |

### วันที่สอง

| เหตุการณ์ | เงินในลิ้นชัก | หมายเหตุ |
|---|---:|---|
| เปิดวันใส่ 40,000 | 40,000 | ใส่ใหม่ ไม่ยกจากวันก่อน |
| รับเงินสดขาย Lot 15,000 | 55,000 | เพิ่มลิ้นชัก |
| ซื้อของเงินสด 30,000 | 25,000 | ตัดจากลิ้นชัก |
| ปิดยอด (นับได้ 25,000) | — | variance = 0, ปิดทันที |

---

## 4. ขอบเขต implementation

### อยู่ใน scope

- `CashSession` model — open/close/fundDrawer logic
- cash session controller/API
- หน้า `cash-sessions.html` และ `cash-sessions.js`
- integration กับ PO, BusinessExpense และ SaleLot (cash payment)
- unit tests + integration tests

### ห้ามแตะ

- FIFO และ stock costing
- Stock transfer
- Seller encryption
- Receipt/thermal printer

---

## 5. Acceptance invariants

- เปิดวัน = `opening_actual` = ยอดที่ใส่ ไม่ยกจากวันก่อน
- ปิดวัน = บันทึก variance อย่างเดียว **ไม่ย้ายเงิน**
- เปิดรอบใหม่วันเดียวกัน = ล้าง movement เก่า เริ่มใหม่จากยอดที่ใส่
- เงินในลิ้นชักติดลบไม่ได้
- variance > 100 ต้องรออนุมัติ
- variance ≠ 0 ต้องระบุเหตุผล
- bank transfer ไม่แตะลิ้นชัก

---

## 6. ไฟล์ที่เกี่ยวข้อง

| ไฟล์ | หน้าที่ |
|------|--------|
| `customizations/api/Models/CashSession.php` | Business logic หลัก |
| `customizations/api/Controllers/CashSessionsController.php` | API endpoints |
| `customizations/api/Models/CashDepositRequest.php` | ฝากเงินสด |
| `base-pos/assets/js/cash-sessions.js` | Frontend |
| `base-pos/admin/cash-sessions.html` | UI |
| `tests/Unit/CashSessionTest.php` | Unit tests |
| `tests/api/test_cash_position.sh` | Integration tests |
